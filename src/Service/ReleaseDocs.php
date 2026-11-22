<?php

namespace Base\Forge\Service;

use Base\Forge\Entity\Release;
use Git\Service\Git2Service;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A release's documentation: a folder of Markdown pages (and the pictures they show) kept in
 * the storage beside its builds, forge.storage_dir/<software>/<version>/docs/. It comes in three
 * ways, the last one written winning file by file:
 *
 *  - the docs/ folder of a build (a zip, at its root or under its one top folder), taken when the
 *    build is attached (ArtifactBuilder::attach(): the back office, the publish API,
 *    forge:artifact:attach);
 *  - the docs/ folder of the repository at the release's tag, read on first use when
 *    git/git-bundle is installed and the software has a repository;
 *  - Markdown written in the back office (write()), or a zip or Markdown file uploaded there or
 *    sent with the publish API (import()).
 *
 * The pages are read by omnidocs/markdown when it is installed - its navigation, headings,
 * callouts and code frames -, else by league/commonmark when that is, else shown as the
 * Markdown they are.
 */
class ReleaseDocs
{
    /** What a docs folder may hold. */
    public const EXTENSIONS = ['md', 'markdown', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp'];

    private const MARKDOWN = ['md', 'markdown'];

    private const CONTENT_TYPES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
    ];

    /** The largest file a docs folder takes, in bytes. */
    private const MAX_FILE = 8 * 1024 * 1024;

    public function __construct(
        #[Autowire('%forge.storage_dir%')] private readonly string $storageDir,
        private readonly ?Git2Service $git = null,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /** The folder the release's documentation is kept in. */
    public function folder(Release $release): string
    {
        $software = $release->getSoftware() ?? throw new \LogicException('A release without software has no documentation.');

        return sprintf('%s/%s/%s/docs', rtrim($this->storageDir, '/'), $software->getSlug(), $release->getVersion());
    }

    public function has(Release $release): bool
    {
        return [] !== $this->files($release);
    }

    /**
     * Its pages, in reading order: the home first (index.md or README.md), then the others by
     * their path.
     *
     * @return list<array{path: string, title: string}>
     */
    public function pages(Release $release): array
    {
        $pages = [];
        foreach ($this->files($release) as $file) {
            if (!\in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), self::MARKDOWN, true)) {
                continue;
            }
            $path = self::pagePath($file);
            $pages[$path] = ['path' => $path, 'title' => self::title((string) file_get_contents($this->folder($release).'/'.$file), $path)];
        }
        uksort($pages, fn (string $a, string $b) => ('' === $a ? -1 : ('' === $b ? 1 : strnatcasecmp($a, $b))));

        return array_values($pages);
    }

    /**
     * One page, rendered: its title, its HTML, its headings (level, id, text). Its links to the
     * other pages and its pictures go through $link (a page's path, or a file's path under docs/).
     *
     * @param callable(string $path, bool $image): string $link
     *
     * @return array{path: string, title: string, html: string, headings: list<array{level: int, id: string, text: string}>, renderer: string}|null
     */
    public function page(Release $release, string $path, callable $link): ?array
    {
        $file = $this->pageFile($release, $path);
        if (null === $file) {
            return null;
        }
        $markdown = (string) file_get_contents($this->folder($release).'/'.$file);
        $title = self::title($markdown, $path);
        $base = \dirname($file);
        $links = function (string $url, bool $image) use ($link, $base): ?string {
            if ('' === $url || preg_match('~^([a-z][a-z0-9+.-]*:|//|#)~i', $url)) {
                return null;
            }
            [$target, $fragment] = array_pad(explode('#', $url, 2), 2, null);
            $target = self::normalize(('.' === $base ? '' : $base.'/').$target);
            if (null === $target) {
                return null;
            }
            $extension = strtolower(pathinfo($target, PATHINFO_EXTENSION));
            $address = !$image && (\in_array($extension, self::MARKDOWN, true) || '' === $extension) ? $link(self::pagePath($target), false) : $link($target, true);

            return $address.(null !== $fragment ? '#'.$fragment : '');
        };

        if (class_exists('Omnidocs\\Markdown\\MarkdownRenderer')) {
            $rendered = (new \Omnidocs\Markdown\MarkdownRenderer())->render($markdown, $links);
            $headings = [];
            foreach ($rendered->headings as $heading) {
                $headings[] = ['level' => (int) $heading->level, 'id' => (string) $heading->id, 'text' => (string) $heading->text];
            }

            return ['path' => $path, 'title' => $rendered->title ?? $title, 'html' => $rendered->html, 'headings' => $headings, 'renderer' => 'omnidocs'];
        }

        if (class_exists('League\\CommonMark\\GithubFlavoredMarkdownConverter')) {
            $converter = new \League\CommonMark\GithubFlavoredMarkdownConverter(['html_input' => 'escape', 'allow_unsafe_links' => false]);
            $html = (string) $converter->convert($markdown);
            $html = preg_replace_callback('~(href|src)="([^"]*)"~', function (array $match) use ($links): string {
                $address = $links(html_entity_decode($match[2]), 'src' === $match[1]);

                return null === $address ? $match[0] : $match[1].'="'.htmlspecialchars($address).'"';
            }, $html) ?? $html;

            return ['path' => $path, 'title' => $title, 'html' => $html, 'headings' => [], 'renderer' => 'commonmark'];
        }

        return ['path' => $path, 'title' => $title, 'html' => '<pre class="forge-docs-source">'.htmlspecialchars($markdown).'</pre>', 'headings' => [], 'renderer' => 'plain'];
    }

    /**
     * A picture of the docs folder, by its path under it; null when it has none such.
     *
     * @return array{file: string, type: string}|null
     */
    public function asset(Release $release, string $path): ?array
    {
        $path = self::normalize($path);
        $extension = strtolower(pathinfo((string) $path, PATHINFO_EXTENSION));
        if (null === $path || !isset(self::CONTENT_TYPES[$extension]) || !\in_array($path, $this->files($release), true)) {
            return null;
        }

        return ['file' => $this->folder($release).'/'.$path, 'type' => self::CONTENT_TYPES[$extension]];
    }

    /** The Markdown of a page as it is kept, for the back office; null when it has none. */
    public function source(Release $release, string $path = ''): ?string
    {
        $file = $this->pageFile($release, $path);

        return null === $file ? null : (string) file_get_contents($this->folder($release).'/'.$file);
    }

    /** A page written in the back office (its home by default); empty Markdown removes it. */
    public function write(Release $release, string $markdown, string $path = ''): void
    {
        $file = $this->pageFile($release, $path) ?? (('' === $path ? 'index' : self::normalize($path) ?? throw new \InvalidArgumentException(sprintf('"%s" is not a page path.', $path))).'.md');
        $target = $this->folder($release).'/'.$file;
        if ('' === trim($markdown)) {
            $this->filesystem->remove($target);

            return;
        }
        $this->filesystem->dumpFile($target, str_replace("\r\n", "\n", $markdown));
    }

    /**
     * A docs folder sent as a file: a zip (its docs/ folder, or every page it holds when it has
     * none) or one Markdown file (the home). Returns how many files were taken.
     */
    public function import(Release $release, string $file, ?string $filename = null): int
    {
        $extension = strtolower(pathinfo($filename ?? $file, PATHINFO_EXTENSION));
        if (\in_array($extension, self::MARKDOWN, true)) {
            $this->write($release, (string) file_get_contents($file));

            return 1;
        }
        if ('zip' !== $extension) {
            throw new \InvalidArgumentException(sprintf('Documentation comes as a zip or a Markdown file, not "%s".', basename($filename ?? $file)));
        }

        return $this->extract($release, $file, true);
    }

    /**
     * The docs/ folder of a zip (a build, a docs archive) copied into the release's: at the zip's
     * root or under its one top folder ("Game/docs/"). With $anywhere, a zip without docs/ gives
     * every page it holds. Returns how many files were taken.
     */
    public function extract(Release $release, string $zipFile, bool $anywhere = false): int
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($zipFile, \ZipArchive::RDONLY)) {
            return 0;
        }

        try {
            $entries = [];
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = (string) $zip->getNameIndex($i);
                if (preg_match('~^(?:[^/]+/)?docs/(.+)$~', $name, $match)) {
                    $entries[$i] = $match[1];
                }
            }
            if (!$entries && $anywhere) {
                for ($i = 0; $i < $zip->numFiles; ++$i) {
                    $entries[$i] = (string) $zip->getNameIndex($i);
                }
            }

            $taken = 0;
            foreach ($entries as $index => $relative) {
                $relative = self::normalize($relative);
                $stat = $zip->statIndex($index);
                if (null === $relative || str_ends_with((string) $zip->getNameIndex($index), '/') || !\in_array(strtolower(pathinfo($relative, PATHINFO_EXTENSION)), self::EXTENSIONS, true) || ($stat['size'] ?? 0) > self::MAX_FILE) {
                    continue;
                }
                $content = $zip->getFromIndex($index);
                if (false === $content) {
                    continue;
                }
                $this->filesystem->dumpFile($this->folder($release).'/'.$relative, $content);
                ++$taken;
            }

            return $taken;
        } finally {
            $zip->close();
        }
    }

    /**
     * The docs/ folder of the repository at the release's tag (git/git-bundle), copied once.
     * Returns how many files were taken; 0 when there is no repository to read or nothing in it.
     */
    public function fromRepository(Release $release): int
    {
        $software = $release->getSoftware();
        $repository = $software?->getGitRepository();
        $ref = $release->getTag() ?? $release->getCommitSha();
        if (null === $this->git || null === $repository || null === $ref) {
            return 0;
        }

        $taken = 0;
        try {
            foreach ($this->git->walkTree($repository, $ref) as $path => $file) {
                if (!str_starts_with((string) $path, 'docs/')) {
                    continue;
                }
                $relative = self::normalize(substr((string) $path, 5));
                if (null === $relative || !\in_array(strtolower(pathinfo($relative, PATHINFO_EXTENSION)), self::EXTENSIONS, true) || \strlen($file['content']) > self::MAX_FILE) {
                    continue;
                }
                $this->filesystem->dumpFile($this->folder($release).'/'.$relative, $file['content']);
                ++$taken;
            }
        } catch (\Throwable) {
            // A repository that cannot be read (not cloned yet, a tag gone): no documentation from it.
            return 0;
        }

        return $taken;
    }

    /** Its documentation removed: the folder, with whatever came in. */
    public function clear(Release $release): void
    {
        $this->filesystem->remove($this->folder($release));
    }

    /** @return list<string> the files of the docs folder, by their path under it */
    private function files(Release $release): array
    {
        $folder = $this->folder($release);
        if (!is_dir($folder)) {
            // Read from the repository once: an empty folder records that it had nothing.
            $this->fromRepository($release);
            if (!is_dir($folder) && null !== $this->git && $release->getSoftware()?->getGitRepository() && ($release->getTag() || $release->getCommitSha())) {
                $this->filesystem->mkdir($folder);
            }
            if (!is_dir($folder)) {
                return [];
            }
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && \in_array(strtolower($file->getExtension()), self::EXTENSIONS, true)) {
                $files[] = ltrim(str_replace('\\', '/', substr($file->getPathname(), \strlen($folder))), '/');
            }
        }
        sort($files);

        return $files;
    }

    /** The Markdown file of a page ("" the home: index.md, README.md), or null. */
    private function pageFile(Release $release, string $path): ?string
    {
        $path = trim($path, '/');
        $files = $this->files($release);
        $candidates = '' === $path ? ['index.md', 'README.md', 'readme.md', 'index.markdown'] : [$path.'.md', $path.'.markdown', $path.'/index.md', $path.'/README.md'];
        foreach ($candidates as $candidate) {
            if (\in_array($candidate, $files, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /** A Markdown file's page path: "guide/install.md" is "guide/install", "index.md" the home "". */
    private static function pagePath(string $file): string
    {
        $path = preg_replace('~\.(md|markdown)$~i', '', $file) ?? $file;
        $path = preg_replace('~(^|/)(index|README|readme)$~', '', $path) ?? $path;

        return trim($path, '/');
    }

    /** A page's title: its first heading, or its path. */
    private static function title(string $markdown, string $path): string
    {
        $markdown = preg_replace('~^---\R.*?\R---\R~s', '', $markdown) ?? $markdown;
        if (preg_match('~^#\s+(.+?)\s*#*\s*$~m', $markdown, $match)) {
            return trim($match[1]);
        }

        return '' === $path ? 'Documentation' : ucfirst(str_replace(['-', '_'], ' ', basename($path)));
    }

    /** A path under docs/ with no way out of it, or null. */
    private static function normalize(string $path): ?string
    {
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if ('' === $part || '.' === $part) {
                continue;
            }
            if ('..' === $part) {
                if (!$parts) {
                    return null;
                }
                array_pop($parts);
                continue;
            }
            if (str_starts_with($part, '.')) {
                return null;
            }
            $parts[] = $part;
        }

        return $parts ? implode('/', $parts) : null;
    }
}
