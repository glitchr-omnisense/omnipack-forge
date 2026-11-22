<?php

namespace Tests\Base\Forge\Service;

use Base\Forge\Entity\Release;
use Base\Forge\Service\ReleaseDocs;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A release's documentation kept beside its builds: taken from a zip's docs/ folder (at its
 * root or under its one top folder), written in the back office, its pages listed and rendered,
 * its links pointing at the other pages, nothing leaving the folder.
 */
final class ReleaseDocsTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        $this->storage = sys_get_temp_dir().'/forge-docs-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->storage);
    }

    public function testABuildsDocsFolderBecomesTheReleasesDocumentation(): void
    {
        $docs = new ReleaseDocs($this->storage);
        $release = $this->release();
        $zip = $this->zip([
            'Game/Game.exe' => 'MZ',
            'Game/docs/index.md' => "# The game\n\nSee [the controls](controls.md#keys) and ![a map](img/map.png).\n",
            'Game/docs/controls.md' => "# Controls\n\n## Keys\n",
            'Game/docs/img/map.png' => 'PNG',
            'Game/docs/../../evil.md' => 'nope',
            'Game/docs/notes.txt' => 'not a page',
        ]);

        self::assertSame(3, $docs->extract($release, $zip));
        self::assertTrue($docs->has($release));
        self::assertSame([['path' => '', 'title' => 'The game'], ['path' => 'controls', 'title' => 'Controls']], $docs->pages($release));
        self::assertFileDoesNotExist($this->storage.'/evil.md');

        $page = $docs->page($release, '', fn (string $path, bool $image) => '/docs/'.$path.($image ? '?image' : ''));
        self::assertSame('The game', $page['title']);
        if ('plain' !== $page['renderer']) {
            self::assertStringContainsString('href="/docs/controls#keys"', $page['html']);
            self::assertStringContainsString('src="/docs/img/map.png?image"', $page['html']);
        }
        self::assertNull($docs->page($release, 'missing', fn () => ''));
        self::assertSame('image/png', $docs->asset($release, 'img/map.png')['type']);
        self::assertNull($docs->asset($release, '../index.md'));
    }

    public function testTheBackOfficeWritesTheHome(): void
    {
        $docs = new ReleaseDocs($this->storage);
        $release = $this->release();
        self::assertFalse($docs->has($release));

        $docs->write($release, "# Read me\r\n\r\nHello.");
        self::assertSame("# Read me\n\nHello.", $docs->source($release));
        self::assertSame('Read me', $docs->pages($release)[0]['title']);

        $docs->write($release, '');
        self::assertFalse($docs->has($release));
    }

    public function testAZipWithoutDocsFolderGivesItsPagesWhenImported(): void
    {
        $docs = new ReleaseDocs($this->storage);
        $release = $this->release();
        self::assertSame(0, $docs->extract($release, $this->zip(['Game/Game.exe' => 'MZ'])));
        self::assertSame(1, $docs->import($release, $this->zip(['guide.md' => "# Guide\n", 'Game.exe' => 'MZ']), 'docs.zip'));
        self::assertSame('guide', $docs->pages($release)[0]['path']);

        $this->expectException(\InvalidArgumentException::class);
        $docs->import($release, __FILE__, 'docs.pdf');
    }

    private function release(): Release
    {
        $software = $this->createStub(\Base\Forge\Entity\Software::class);
        $software->method('getSlug')->willReturn('game');
        $release = new Release(null, '1.0.0');
        $release->setSoftware($software);

        return $release;
    }

    /** @param array<string, string> $files */
    private function zip(array $files): string
    {
        $path = $this->storage.'/upload-'.bin2hex(random_bytes(3)).'.zip';
        (new Filesystem())->mkdir($this->storage);
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }
}
