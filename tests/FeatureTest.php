<?php

namespace Tests\Base\Forge;

use Base\Forge\Feature;
use PHPUnit\Framework\TestCase;

/** The map of what needs more than the core (Feature::PATHS), checked against src/. */
final class FeatureTest extends TestCase
{
    /** What each optional package's classes are named under. */
    private const NAMESPACES = [
        'marketplace' => ['Base\\Marketplace\\', 'Omnitrade\\'],
        'git' => ['Git\\'],
        'hub' => ['Omnihub\\'],
    ];

    public function testEveryPathOfTheMapExists(): void
    {
        $src = \dirname(__DIR__).'/src';
        foreach (array_keys(Feature::PATHS) as $path) {
            self::assertFileExists($src.'/'.rtrim($path, '/'), $path.' is in Feature::PATHS');
        }
    }

    /** A file of the core that names an optional package's class would break an application without it. */
    public function testWhatNamesAnOptionalPackageNeedsItsFeature(): void
    {
        $src = \dirname(__DIR__).'/src';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ('php' !== $file->getExtension()) {
                continue;
            }
            $path = ltrim(str_replace('\\', '/', substr($file->getPathname(), \strlen($src))), '/');
            $needs = array_map(fn (Feature $feature) => $feature->value, Feature::requiredBy($path));
            preg_match_all('/^use ([A-Za-z\\\\]+)[;\s]/m', (string) file_get_contents($file->getPathname()), $imports);
            foreach ($imports[1] as $import) {
                foreach (self::NAMESPACES as $feature => $namespaces) {
                    foreach ($namespaces as $namespace) {
                        if (!str_starts_with($import, $namespace)) {
                            continue;
                        }
                        // The core may name them where it only checks them (ArtifactBuilder's git, optional).
                        if (\in_array($path, self::OPTIONAL_USES[$import] ?? [], true)) {
                            continue;
                        }
                        self::assertContains($feature, $needs, sprintf('%s imports %s: Feature::PATHS must say it needs "%s".', $path, $import, $feature));
                    }
                }
            }
        }
    }

    /** The core's optional uses of a package: a nullable dependency, or a static call made only when the feature is on. */
    private const OPTIONAL_USES = [
        'Git\\Service\\Git2Service' => ['Service/ArtifactBuilder.php', 'Service/ReleaseDocs.php'],
        'Omnihub\\Tarball' => ['Service/ArtifactBuilder.php'],
    ];

    public function testRequiredByReadsFilesAndFolders(): void
    {
        self::assertSame([], Feature::requiredBy('Entity/Software.php'));
        self::assertSame([Feature::MARKETPLACE], Feature::requiredBy('Entity/Product/LicenseOffer.php'));
        self::assertSame([Feature::GIT], Feature::requiredBy('Git/ForgeRepositoryProvider.php'));
        self::assertSame([Feature::HUB, Feature::GIT], Feature::requiredBy('Controller/Client/UpmController.php'));
    }

    public function testOffPathsKeepWhatEveryEnabledFeatureAllows(): void
    {
        self::assertSame([], Feature::offPaths(Feature::cases()));
        $withoutHub = Feature::offPaths([Feature::MARKETPLACE, Feature::GIT]);
        self::assertContains('Controller/Client/UpmController.php', $withoutHub);
        self::assertContains('Service/HubPurchases.php', $withoutHub);
        self::assertNotContains('Controller/Client/ShopController.php', $withoutHub);
        self::assertCount(\count(Feature::PATHS), Feature::offPaths([]));
    }

    public function testAFeatureTurnedOffStaysOff(): void
    {
        $enabled = Feature::enabled(['marketplace' => false, 'git' => false, 'hub' => false]);
        self::assertSame([], $enabled);
    }

    public function testAFeatureAskedForWithoutItsPackageStopsTheContainer(): void
    {
        foreach (Feature::cases() as $feature) {
            if ($feature->isInstalled()) {
                continue;
            }
            $this->expectException(\LogicException::class);
            Feature::enabled([$feature->value => true]);
        }
        self::assertTrue(true, 'every optional package is installed here');
    }
}
