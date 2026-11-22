<?php

namespace Tests\Base\Forge;

use Base\Forge\Entity\Software;
use Base\Forge\Enum\SoftwareKind;
use Base\Forge\Feature;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\Base\Forge\Fixtures\CoreKernel;
use Tests\Base\Forge\Fixtures\WithoutOptionalPackages;

/**
 * The forge on its core alone (Fixtures\CoreKernel): omnipack/marketplace, git/git-bundle,
 * omnisense/omnihub and omnisense/omnistate cannot load in this process (WithoutOptionalPackages),
 * as in an application that did not install them. The container compiles, the core's routes and
 * entities are there and the others are not, and a game goes through: published by the API,
 * listed, its version read as JSON, its documentation shown, its build downloaded.
 */
final class CoreKernelTest extends TestCase
{
    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheCoreRunsWithoutTheOptionalPackages(): void
    {
        if (!class_exists(\Base\BaseBundle::class) || !class_exists(\Symfony\Bundle\FrameworkBundle\FrameworkBundle::class)) {
            self::markTestSkipped('Needs omnisense/omnibase and symfony/framework-bundle installed.');
        }
        if (!\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('Needs pdo_sqlite: the core kernel\'s database.');
        }

        WithoutOptionalPackages::install();
        self::assertSame([], Feature::enabled(), 'no optional package can load here');

        (new \Symfony\Component\Filesystem\Filesystem())->remove(CoreKernel::varDir());
        mkdir(CoreKernel::varDir(), 0777, true);
        $kernel = new CoreKernel('test', true);
        // Symfony's default logger writes to stderr: the separate process would report it as output.
        ob_start();
        try {
            $kernel->boot();
        } finally {
            ob_end_clean();
        }
        $container = $kernel->getContainer();
        self::assertSame([], $container->getParameter('forge.enabled_features'));

        // The routes: the core's, none of the features'.
        $routes = $container->get('router')->getRouteCollection();
        foreach (['forge_downloads', 'forge_software', 'forge_download', 'forge_download_file', 'forge_release_docs', 'forge_play', 'forge_api_software_index', 'forge_api_software', 'forge_api_release_publish', 'forge_admin_release_builds'] as $name) {
            self::assertNotNull($routes->get($name), $name.' is a core route');
        }
        foreach (['forge_shop', 'forge_quote_request', 'forge_account_licenses', 'forge_account_invoices', 'forge_api_license_activate', 'forge_composer_packages', 'forge_upm_package', 'forge_hub_manifest', 'forge_hub_purchase', 'forge_admin_order_paid', 'forge_project_board'] as $name) {
            self::assertNull($routes->get($name), $name.' needs a feature that is off');
        }

        // The entities: the core's mapped, the features' left out.
        $em = $container->get('doctrine')->getManager();
        $mapped = array_map(fn ($metadata) => $metadata->getName(), $em->getMetadataFactory()->getAllMetadata());
        foreach (['Software', 'Release', 'Artifact', 'Download', 'License', 'LicenseSeat', 'LicenseActivation'] as $entity) {
            self::assertContains('Base\\Forge\\Entity\\'.$entity, $mapped);
        }
        foreach (['Product\\LicenseOffer', 'Product\\HourPack', 'Quote', 'Project', 'TimeEntry', 'HourInvoice', 'HubToken', 'HubPurchase', 'Pipeline'] as $entity) {
            self::assertNotContains('Base\\Forge\\Entity\\'.$entity, $mapped);
        }

        $tool = new SchemaTool($em);
        $tool->dropDatabase();
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());

        $game = new Software(null, 'Demo Game', 'demo-game');
        $game->setKind(SoftwareKind::GAME);
        $game->publish();
        $em->persist($game);
        $em->flush();
        self::assertCount(0, $game->getOffers(), 'no licence offer without the marketplace');

        // A build published by the API, its docs/ folder taken.
        $zip = CoreKernel::varDir().'/build.zip';
        $archive = new \ZipArchive();
        $archive->open($zip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $archive->addFromString('Demo/Demo.exe', 'MZ');
        $archive->addFromString('Demo/docs/index.md', "# Demo Game\n\nRead [the guide](guide.md).\n");
        $archive->addFromString('Demo/docs/guide.md', "# The guide\n\nPress start.\n");
        $archive->close();

        $closed = $this->handle($kernel, Request::create('/api/software/demo-game/releases/1.0.0', 'POST', ['platform' => 'windows'], [], [], ['HTTP_AUTHORIZATION' => 'Bearer wrong']));
        self::assertSame(401, $closed->getStatusCode());

        $published = $this->handle($kernel, Request::create('/api/software/demo-game/releases/1.0.0', 'POST', ['platform' => 'windows', 'changelog' => "- First playable\n- Two levels"], [], ['file' => new UploadedFile($zip, 'Demo-Windows.zip', 'application/zip', null, true)], ['HTTP_AUTHORIZATION' => 'Bearer core-token']));
        self::assertSame(201, $published->getStatusCode(), (string) $published->getContent());
        $body = json_decode((string) $published->getContent(), true);
        self::assertTrue($body['published']);
        self::assertSame(['First playable', 'Two levels'], $body['release']['changes']);
        self::assertSame(hash_file('sha256', $zip), $body['release']['files']['windows']['sha256']);

        $version = json_decode((string) $this->handle($kernel, Request::create('/api/software/demo-game'))->getContent(), true);
        self::assertSame('1.0.0', $version['latest']['version']);
        self::assertStringEndsWith('/telechargements/demo-game/1.0.0?format=windows', $version['latest']['files']['windows']['url']);
        self::assertStringEndsWith('/telechargements/demo-game/1.0.0/docs', (string) $version['latest']['docs']);

        // The latest is the highest version published, not the last one out: a fix of 1.0 after 1.1.
        foreach (['1.1.0', '1.0.1'] as $number) {
            $response = $this->handle($kernel, Request::create('/api/software/demo-game/releases/'.$number, 'POST', ['platform' => 'windows', 'changelog' => 'A fix'], [], ['file' => new UploadedFile($zip, 'Demo-Windows.zip', 'application/zip', null, true)], ['HTTP_AUTHORIZATION' => 'Bearer core-token']));
            self::assertSame(201, $response->getStatusCode());
        }
        $versions = json_decode((string) $this->handle($kernel, Request::create('/api/software/demo-game'))->getContent(), true);
        self::assertSame('1.1.0', $versions['latest']['version']);
        self::assertSame(['1.1.0', '1.0.1', '1.0.0'], array_column($versions['versions'], 'version'));

        // The pages: free without the marketplace, its versions and documentation.
        $page = $this->handle($kernel, Request::create('/telechargements/demo-game'));
        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('/telechargements/demo-game/1.0.0/docs', (string) $page->getContent());
        self::assertStringNotContainsString('marketplace', (string) $page->getContent());
        self::assertSame(200, $this->handle($kernel, Request::create('/telechargements'))->getStatusCode());

        $docs = $this->handle($kernel, Request::create('/telechargements/demo-game/latest/docs/guide'));
        self::assertSame(200, $docs->getStatusCode());
        self::assertStringContainsString('Press start.', (string) $docs->getContent());

        // The build, the newest of its platform: a signed link, then the file.
        $download = $this->handle($kernel, Request::create('/telechargements/demo-game/latest?format=windows'));
        self::assertSame(302, $download->getStatusCode());
        self::assertStringContainsString('/telechargements/fichier/', (string) $download->headers->get('Location'));
        $file = $this->handle($kernel, Request::create((string) $download->headers->get('Location')));
        self::assertSame(200, $file->getStatusCode());
        self::assertSame(realpath(CoreKernel::varDir().'/storage/forge/demo-game/1.1.0/windows/Demo-Windows.zip'), realpath($file->getFile()->getPathname()));

        // What the features would answer is not there.
        foreach (['/support', '/compte/licences', '/composer/packages.json', '/upm/-/hub.json'] as $path) {
            self::assertSame(404, $this->handle($kernel, Request::create($path))->getStatusCode(), $path);
        }

        $kernel->shutdown();
    }

    private function handle(CoreKernel $kernel, Request $request): Response
    {
        $kernel->getContainer()->get('doctrine')->getManager()->clear();
        ob_start();
        try {
            return $kernel->handle($request);
        } finally {
            ob_end_clean();
        }
    }
}
