<?php

namespace Tests\Base\Forge\Fixtures;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * The forge on its core alone: omnibase, its back office and the forge, on Symfony's framework,
 * security, Twig and Doctrine - no omnipack/marketplace, no git/git-bundle, no omnisense/omnihub,
 * no omnisense/omnistate, and no application of its own (its User is omnibase's, aliased). A
 * SQLite database in its cache folder; the forge's routes through its loader (type: forge), as an
 * application installs them. Booted by CoreKernelTest, in a process where the optional packages
 * cannot load (WithoutOptionalPackages).
 */
final class CoreKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        $bundles = [
            \Symfony\Bundle\FrameworkBundle\FrameworkBundle::class,
            \Symfony\Bundle\SecurityBundle\SecurityBundle::class,
            \Symfony\Bundle\TwigBundle\TwigBundle::class,
            \Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class,
            \Base\BaseBundle::class,
            \Base\Admin\AdminBundle::class,
            \Base\Forge\ForgeBundle::class,
        ];
        // format_date and the like, which the forge's pages use (twig/extra-bundle with twig/intl-extra).
        if (class_exists(\Twig\Extra\TwigExtraBundle\TwigExtraBundle::class)) {
            $bundles[] = \Twig\Extra\TwigExtraBundle\TwigExtraBundle::class;
        }
        foreach ($bundles as $bundle) {
            yield new $bundle();
        }
    }

    public function getProjectDir(): string
    {
        return __DIR__.'/core';
    }

    public function getCacheDir(): string
    {
        return self::varDir().'/cache/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return self::varDir().'/log';
    }

    /** Outside the checkout and the host: a folder of the system's temporary one. */
    public static function varDir(): string
    {
        return sys_get_temp_dir().'/forge-core-kernel';
    }

    private function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        $container->import(__DIR__.'/core/config/packages.php');
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', 'forge');
    }
}
