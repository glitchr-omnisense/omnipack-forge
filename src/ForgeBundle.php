<?php

namespace Base\Forge;

use Base\Bundle\AbstractBaseBundle;
use Base\Forge\DependencyInjection\FeatureMappingPass;
use Base\Traits\SingletonTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * A software forge: software and its versions, their builds per platform, downloads, the
 * documentation of each version, a publish API. The rest switches on with its package
 * (Base\Forge\Feature): licences, purchases, support hours, quotes and client projects with
 * omnipack/marketplace; releases from git tags and the Composer index with git/git-bundle; the
 * Unity registry with omnisense/omnihub.
 */
class ForgeBundle extends AbstractBaseBundle
{
    // Own singleton storage rather than sharing AbstractBaseBundle's - see
    // that class's constructor for why every concrete bundle needs this.
    use SingletonTrait;

    // The trait's protected no-op __construct() would hide the inherited
    // one; redeclared public, as MarketplaceBundle and AdminBundle do.
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Modern bundle layout: the class lives in src/, the bundle root is the
     * package root - so TwigBundle picks up ./templates as @Forge and
     * Doctrine's auto_mapping ./src/Entity.
     */
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // base-bundle's App\-wins convention, as base-bundle-market's: every
        // Base\Forge\Entity\* is aliased onto App\Entity\Forge\* unless the
        // application declares a real class there - which is how an app
        // extends a forge entity (its own Software fields, say) without
        // touching the bundle.
        // Only the classes of the features whose package is installed: the others may not load.
        $this->mapInstalled('Entity', 'App\Entity\Forge');
        $this->mapInstalled('Repository', 'App\Repository\Forge');

        // The entities of a feature that is off stay out of Doctrine's mapping.
        $container->addCompilerPass(new FeatureMappingPass($this->getPath().'/src'));
    }

    /** setMapping() over src/<folder>, less the files of the features whose package is missing. */
    private function mapInstalled(string $folder, string $outputNamespace): void
    {
        $off = Feature::offPaths(Feature::enabled());
        $aliases = [];
        foreach ($this->getAllClasses($this->getPath().'/src/'.$folder, 'Base\\Forge\\'.$folder) as $class) {
            $class = trim($class, '\\');
            $path = $folder.'/'.str_replace('\\', '/', $class).'.php';
            if (array_filter($off, fn (string $prefix) => $path === $prefix || (str_ends_with($prefix, '/') && str_starts_with($path, $prefix)))) {
                continue;
            }
            $aliases['Base\\Forge\\'.$folder.'\\'.$class] = $outputNamespace.'\\'.$class;
        }

        $this->setAlias($aliases);
    }
}
