<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Tests\Base\Forge\Fixtures\CoreKernel;

/*
 * CoreKernel's configuration: what an application on the forge's core alone configures.
 */
return static function (ContainerConfigurator $container): void {
    $var = CoreKernel::varDir();

    $container->extension('framework', [
        'secret' => 'forge-core-kernel',
        'test' => true,
        'http_method_override' => false,
        'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
        'router' => ['utf8' => true],
        'csrf_protection' => true,
    ]);

    $container->extension('security', [
        'password_hashers' => ['Base\\Entity\\User' => 'plaintext'],
        'providers' => ['users' => ['id' => 'Base\\Security\\UserProvider']],
        'firewalls' => ['main' => ['lazy' => true, 'provider' => 'users']],
    ]);

    $container->extension('twig', [
        'default_path' => '%kernel.project_dir%/templates',
    ]);

    $container->extension('doctrine', [
        'dbal' => ['url' => 'sqlite:///'.$var.'/core.sqlite'],
        'orm' => [
            'naming_strategy' => 'Base\\Database\\Mapping\\NamingStrategy',
            'class_metadata_factory_name' => 'Base\\Database\\Mapping\\ClassMetadataFactory',
            'auto_mapping' => true,
        ],
    ]);

    $container->extension('base', [
        'persistence' => 'doctrine',
    ]);

    // Quiet: Symfony's default logger writes to stderr, which a test run in its own process reads as a failure.
    $container->services()->set('logger', \Psr\Log\NullLogger::class);

    $container->extension('forge', [
        'storage_dir' => $var.'/storage/forge',
        'accel_prefix' => '',
        'publish_token' => 'core-token',
    ]);
};
