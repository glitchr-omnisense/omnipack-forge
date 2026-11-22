<?php

namespace Base\Forge\Controller\Client;

use Base\Contract\UnitOfWork;
use Base\Forge\Entity\Artifact;
use Base\Forge\Entity\Release;
use Base\Forge\Entity\Software;
use Base\Forge\Enum\Platform;
use Base\Forge\Repository\SoftwareRepository;
use Base\Forge\Service\ArtifactBuilder;
use Base\Forge\Service\ReleaseDocs;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The versions, for machines - a game or an application that checks for a newer version, a CI
 * or an editor that publishes one:
 *
 *   GET  /api/software                             every published software, with its latest version
 *   GET  /api/software/{slug}                      its latest version and every published one, with their builds
 *   POST /api/software/{slug}/releases/{version}   a build published: multipart `file`, `platform`
 *                                                  (Platform: webgl, windows, macos, linux, android),
 *                                                  `changelog` (what changed; `notes` is read too),
 *                                                  `published` (1|0, optional), `docs` (a zip or a
 *                                                  Markdown file, optional)
 *
 * A version reads as {software, version, published, changelog, changes, files: {platform: {url,
 * filename, size, sha256, uploaded}}, docs}: the url is the download's (forge_download), the
 * size and SHA-256 are there to check what arrived. Publishing is opened by forge.publish_token
 * alone, sent as `Authorization: Bearer <token>`; without one the route answers 503. The
 * release is made when it is new - published at once unless `published` is 0 -, the build of
 * that platform replaced when it had one.
 */
#[Route('/api/software')]
class ReleaseApiController extends AbstractController
{
    public function __construct(
        private readonly SoftwareRepository $software,
        private readonly ArtifactBuilder $builder,
        private readonly ReleaseDocs $docs,
        private readonly UnitOfWork $unitOfWork,
        #[Autowire('%forge.publish_token%')] private readonly ?string $publishToken = null,
        #[Autowire('%forge.max_build_size%')] private readonly int $maxBuildSize = 2 * 1024 * 1024 * 1024,
    ) {
    }

    #[Route('', name: 'forge_api_software_index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $software = [];
        foreach ($this->software->findVisible() as $item) {
            $latest = $item->getLatestRelease();
            $software[$item->getSlug()] = ['name' => $item->getName(), 'kind' => $item->getKind()->value, 'latest' => $latest ? $this->card($latest) : null];
        }

        return $this->json(['software' => $software, 'platforms' => Platform::values()]);
    }

    #[Route('/{slug}', name: 'forge_api_software', requirements: ['slug' => '[a-z0-9\-]+'], methods: ['GET'])]
    public function show(string $slug): JsonResponse
    {
        $software = $this->find($slug);
        if (!$software) {
            return $this->fail('unknown_software', 404);
        }
        $latest = $software->getLatestRelease();

        return $this->json([
            'software' => $software->getSlug(),
            'name' => $software->getName(),
            'kind' => $software->getKind()->value,
            'latest' => $latest ? $this->card($latest) : null,
            'versions' => array_map($this->card(...), $software->getPublishedReleases()),
        ]);
    }

    #[Route('/{slug}/releases/{version}', name: 'forge_api_release_publish', requirements: ['slug' => '[a-z0-9\-]+', 'version' => '[0-9A-Za-z.\-]+'], methods: ['POST'])]
    public function publish(Request $request, string $slug, string $version): JsonResponse
    {
        if (null === $this->publishToken || '' === $this->publishToken) {
            return $this->fail('publishing_closed', 503);
        }
        if (!preg_match('/^Bearer\s+(\S+)$/i', trim((string) $request->headers->get('Authorization')), $match) || !hash_equals($this->publishToken, $match[1])) {
            return $this->json(['error' => 'bad_token'], 401, ['WWW-Authenticate' => 'Bearer']);
        }

        $software = $this->software->findOneBy(['slug' => $slug]);
        if (!$software instanceof Software) {
            return $this->fail('unknown_software', 404);
        }
        if (\strlen($version) > 64 || 'latest' === $version) {
            return $this->fail('bad_version');
        }
        $platform = Platform::tryFrom((string) $request->request->get('platform', $request->request->get('format', '')));
        if (!$platform) {
            return $this->json(['error' => 'bad_platform', 'platforms' => Platform::values()], 400);
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            // A body over post_max_size reaches PHP as nothing at all: no file, no fields.
            $empty = 0 === $request->request->count();

            return $this->fail($empty && $request->headers->get('Content-Length') > 0 ? 'too_large' : 'no_file', $empty && $request->headers->get('Content-Length') > 0 ? 413 : 400);
        }
        if (!$file->isValid()) {
            $tooLarge = \in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true);

            return $this->fail($tooLarge ? 'too_large' : 'bad_upload', $tooLarge ? 413 : 400);
        }
        if ($file->getSize() > $this->maxBuildSize) {
            return $this->fail('too_large', 413);
        }

        $release = $software->findRelease($version);
        $new = null === $release;
        $release ??= new Release($software, $version);

        $changelog = $request->request->has('changelog') ? $request->request->get('changelog') : $request->request->get('notes');
        if (null !== $changelog && '' !== trim((string) $changelog)) {
            $release->setChangelog(trim(str_replace("\r\n", "\n", (string) $changelog)));
        }
        $published = $request->request->has('published') ? $request->request->getBoolean('published') : null;
        if (true === $published || ($new && null === $published)) {
            if (!$release->isPublished()) {
                $release->setPublishedAt(new \DateTimeImmutable());
            }
        } elseif (false === $published) {
            $release->setPublishedAt(null);
        }

        try {
            $artifact = $this->builder->attach($release, $platform, $file->getPathname(), $file->getClientOriginalName());
            $docs = $request->files->get('docs');
            if ($docs instanceof UploadedFile && $docs->isValid()) {
                $this->docs->import($release, $docs->getPathname(), $docs->getClientOriginalName());
            }
        } catch (\InvalidArgumentException $e) {
            if ($new) {
                $software->removeRelease($release);
            }

            return $this->json(['error' => 'refused', 'reason' => $e->getMessage()], 400);
        }

        $this->unitOfWork->persist($release);
        $this->unitOfWork->flush();

        return $this->json(['published' => $release->isPublished(), 'release' => $this->card($release), 'artifact' => $this->build($release, $artifact)], $new ? 201 : 200);
    }

    /** One version, as JSON: its number, when it went out, its changelog, a build per platform with where to get it. */
    private function card(Release $release): array
    {
        $files = [];
        foreach ($release->getPlatformArtifacts() as $artifact) {
            $files[$artifact->getFormat()] = $this->build($release, $artifact);
        }
        $software = $release->getSoftware();

        return [
            'software' => $software?->getSlug(),
            'version' => $release->getVersion(),
            'published' => $release->getPublishedAt()?->format(\DateTimeInterface::ATOM),
            'changelog' => $release->getChangelog(),
            'changes' => $release->getChanges(),
            'files' => $files,
            'docs' => $software && $this->docs->has($release) ? $this->generateUrl('forge_release_docs', ['slug' => $software->getSlug(), 'version' => $release->getVersion()], UrlGeneratorInterface::ABSOLUTE_URL) : null,
        ];
    }

    private function build(Release $release, Artifact $artifact): array
    {
        return [
            'url' => $this->generateUrl('forge_download', ['slug' => $release->getSoftware()?->getSlug(), 'version' => $release->getVersion(), 'format' => $artifact->getFormat()], UrlGeneratorInterface::ABSOLUTE_URL),
            'filename' => $artifact->getFilename(),
            'size' => $artifact->getSize(),
            'sha256' => $artifact->getSha256(),
            'uploaded' => $artifact->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    private function find(string $slug): ?Software
    {
        $software = $this->software->findOneBy(['slug' => $slug]);

        return $software instanceof Software && $software->isVisible() ? $software : null;
    }

    private function fail(string $error, int $status = 400): JsonResponse
    {
        return $this->json(['error' => $error], $status);
    }
}
