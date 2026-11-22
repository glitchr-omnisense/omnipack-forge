<?php

namespace Base\Forge\Controller\Client;

use Base\Entity\User;
use Base\Forge\Entity\Artifact;
use Base\Forge\Entity\License;
use Base\Forge\Entity\Release;
use Base\Forge\Entity\Product\LicenseOffer;
use Base\Forge\Entity\Software;
use Base\Forge\Repository\LicenseRepository;
use Base\Forge\Repository\SoftwareRepository;
use Base\Forge\Security\Voter\ArtifactVoter;
use Base\Forge\Service\ArtifactFiles;
use Base\Forge\Service\ReleaseDocs;
use Base\Service\DownloadLinks;
use Base\Database\Repository\Repositories;
use Base\Contract\UnitOfWork;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The software page, its versions and their documentation, and its downloads. A download is
 * two steps: the version link checks who you are against the licence (ArtifactVoter; every
 * software is free without omnipack/marketplace) and redirects to a signed, short-lived file
 * URL; that URL is served by nginx (X-Accel-Redirect to forge.accel_prefix) or, without it, by
 * PHP. "latest" stands for the newest published version (of the build asked for).
 */
class DownloadController extends AbstractController
{
    public function __construct(
        private readonly SoftwareRepository $software,
        private readonly LicenseRepository $licenses,
        private readonly UnitOfWork $unitOfWork,
        private readonly Repositories $repositories,
        private readonly DownloadLinks $links,
        private readonly ArtifactFiles $files,
        private readonly ReleaseDocs $docs,
        #[Autowire('%forge.download_ttl%')] private readonly int $downloadTtl = 600,
        /** @var list<string> */
        #[Autowire('%forge.enabled_features%')] private readonly array $features = [],
    ) {
    }

    #[Route('/telechargements', name: 'forge_downloads')]
    public function index(): Response
    {
        $software = $this->software->findDownloadable();

        return $this->render('@Forge/client/downloads/index.html.twig', [
            'software' => $software,
            'licenses' => $this->licensesBySoftware(),
        ]);
    }

    #[Route('/telechargements/{slug}', name: 'forge_software', requirements: ['slug' => '[a-z0-9\-]+'])]
    public function software(string $slug): Response
    {
        $software = $this->findSoftware($slug);

        return $this->render('@Forge/client/downloads/software.html.twig', [
            'software' => $software,
            'licenses' => $this->licensesBySoftware()[$software->getId()] ?? [],
            'offers' => $this->sells() ? $software->getOffers()->filter(fn (LicenseOffer $offer) => $offer->isForSell())->toArray() : [],
        ]);
    }

    /**
     * A version's documentation, page by page ("" its home); a picture of it by its path. Read
     * where the release's downloads are: by anyone, but for a draft (the staff).
     */
    #[Route('/telechargements/{slug}/{version}/docs/{path}', name: 'forge_release_docs', requirements: ['slug' => '[a-z0-9\-]+', 'version' => '[0-9A-Za-z.\-]+', 'path' => '.*'], defaults: ['path' => ''], methods: ['GET', 'HEAD'])]
    public function docs(string $slug, string $version, string $path): Response
    {
        $software = $this->findSoftware($slug);
        $release = $this->findRelease($software, $version) ?? throw $this->createNotFoundException(sprintf('No version %s of %s.', $version, $software));
        if (!$release->isPublished() && !$this->isGranted('ROLE_STAFF')) {
            throw $this->createNotFoundException(sprintf('No version %s of %s.', $version, $software));
        }
        $path = trim($path, '/');

        if ($asset = $this->docs->asset($release, $path)) {
            $response = new BinaryFileResponse($asset['file'], Response::HTTP_OK, ['Content-Type' => $asset['type'], 'X-Content-Type-Options' => 'nosniff']);
            if ('image/svg+xml' === $asset['type']) {
                $response->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");
            }

            return $response->setPublic()->setMaxAge(3600);
        }

        $link = fn (string $target, bool $image): string => $this->generateUrl('forge_release_docs', ['slug' => $software->getSlug(), 'version' => $release->getVersion(), 'path' => $target]);
        $page = $this->docs->page($release, $path, $link) ?? throw $this->createNotFoundException(sprintf('No page "%s" in the documentation of %s.', $path, $release));

        return $this->render('@Forge/client/downloads/docs.html.twig', [
            'software' => $software,
            'release' => $release,
            'page' => $page,
            'pages' => $this->docs->pages($release),
            'versions' => array_values(array_filter($software->getPublishedReleases(), fn (Release $other) => $other === $release || $this->docs->has($other))),
        ]);
    }

    /** ?format= another of the release's artifacts than its zip: a platform's build (webgl, windows, macos, linux, android), the UPM tarball (upm). */
    #[Route('/telechargements/{slug}/{version}', name: 'forge_download', requirements: ['slug' => '[a-z0-9\-]+', 'version' => '[0-9A-Za-z.\-]+'])]
    public function download(Request $request, string $slug, string $version): Response
    {
        $software = $this->findSoftware($slug);
        $format = (string) $request->query->get('format', 'zip');
        $release = 'latest' === $version ? $software->getLatestRelease($format) : $software->findRelease($version);
        $artifact = $release?->getArtifact($format) ?? throw $this->createNotFoundException(sprintf('No %s archive for %s %s.', $format, $software, $version));

        if (!$this->isGranted(ArtifactVoter::DOWNLOAD, $artifact)) {
            if (!$this->getUser()) {
                return $this->redirectToRoute('security_login', ['_target_path' => $this->generateUrl('forge_download', ['slug' => $slug, 'version' => $version] + ('zip' === $format ? [] : ['format' => $format]))]);
            }
            $this->addFlash('warning', '@forge.download.license_required');

            return $this->redirectToRoute('forge_software', ['slug' => $slug]);
        }

        $license = null;
        $user = $this->getUser();
        if ($this->sells() && !$software->isFree() && $user instanceof User) {
            foreach ($this->licenses->findValidFor($user, $software) as $candidate) {
                if ($candidate->covers($release)) {
                    $license = $candidate;
                    break;
                }
            }
        }

        return $this->redirect($this->links->sign('forge_download_file', ['id' => $artifact->getId(), 'filename' => $artifact->getFilename()] + array_filter(['license' => $license?->getId()]), $this->downloadTtl));
    }

    /** A version by its number, or "latest". */
    private function findRelease(Software $software, string $version): ?Release
    {
        return 'latest' === $version ? $software->getLatestRelease() : $software->findRelease($version);
    }

    /** Whether licences are sold here: omnipack/marketplace's feature (Base\Forge\Feature::MARKETPLACE). */
    private function sells(): bool
    {
        return \in_array('marketplace', $this->features, true);
    }

    #[Route('/telechargements/fichier/{id}/{filename}', name: 'forge_download_file', requirements: ['id' => '\d+', 'filename' => '[^/]+'])]
    public function serve(Request $request, int $id): Response
    {
        // A plain 403, not the security layer's AccessDeniedException: that
        // one sends a visitor to the login page, and a link that is expired
        // or forged is not solved by signing in (nor is Composer's request).
        if (!$this->links->verify($request)) {
            throw new AccessDeniedHttpException('This download link is invalid or has expired.');
        }

        $artifact = $this->repositories->of(Artifact::class)->find($id)
            ?? throw $this->createNotFoundException('Unknown artifact.');

        $license = $request->query->getInt('license') ? $this->repositories->of(License::class)->find($request->query->getInt('license')) : null;

        return $this->files->respond($request, $artifact, $license, $this->getUser(), 'composer' === $request->query->get('channel') ? 'composer' : 'web');
    }

    private function findSoftware(string $slug): Software
    {
        $software = $this->software->findOneBy(['slug' => $slug]);
        if (!$software instanceof Software || !$software->isVisible()) {
            throw $this->createNotFoundException(sprintf('No software "%s".', $slug));
        }

        return $software;
    }

    /** @return array<int, list<License>> the valid licences a seat of which the signed-in user holds, by software id */
    private function licensesBySoftware(): array
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$this->sells()) {
            return [];
        }

        $bySoftware = [];
        foreach ($this->licenses->findHeldBy($user) as $license) {
            if ($license->isValid()) {
                $bySoftware[(int) $license->getSoftware()?->getId()][] = $license;
            }
        }

        return $bySoftware;
    }
}
