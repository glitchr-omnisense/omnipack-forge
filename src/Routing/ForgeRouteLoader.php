<?php

namespace Base\Forge\Routing;

use Base\Forge\Feature;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Routing\RouteCollection;

/**
 * The forge's routes, those of the features that are on (Base\Forge\Feature):
 *
 *     # config/routes.yaml
 *     forge:
 *         resource: .
 *         type: forge
 *
 * Each controller of src/Controller is read with the attribute loader, the ones of a feature
 * that is off left out - their routes do not exist, and their classes may not even load. The
 * back office's controllers come only with omnipack/admin. An application that installs every
 * feature may import "@ForgeBundle/src/Controller" (type: attribute) as before 3.0.
 */
#[AutoconfigureTag('routing.loader')]
final class ForgeRouteLoader extends Loader
{
    private bool $loaded = false;

    /** @param list<string> $features */
    public function __construct(
        #[Autowire('%forge.enabled_features%')] private readonly array $features = [],
        ?string $env = null,
    ) {
        parent::__construct($env);
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        if ($this->loaded) {
            throw new \RuntimeException('The "forge" routes are imported once.');
        }
        $this->loaded = true;

        $src = \dirname(__DIR__);
        $enabled = array_map(fn (string $value) => Feature::from($value), $this->features);
        $on = array_map(fn (Feature $feature) => $feature->value, $enabled);
        $routes = new RouteCollection();

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src.'/Controller', \FilesystemIterator::SKIP_DOTS));
        $files = [];
        foreach ($iterator as $file) {
            if ($file->isFile() && 'php' === $file->getExtension()) {
                $files[] = ltrim(str_replace('\\', '/', substr($file->getPathname(), \strlen($src))), '/');
            }
        }
        sort($files);

        foreach ($files as $path) {
            if (array_diff(array_map(fn (Feature $feature) => $feature->value, Feature::requiredBy($path)), $on)) {
                continue;
            }
            if (str_starts_with($path, 'Controller/Admin/') && !class_exists('Base\\Admin\\Controller\\AbstractCrudController')) {
                continue;
            }
            $class = 'Base\\Forge\\'.str_replace('/', '\\', substr($path, 0, -4));
            $routes->addCollection($this->import($class, 'attribute'));
            $routes->addResource(new \Symfony\Component\Config\Resource\FileResource($src.'/'.$path));
        }

        return $routes;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return 'forge' === $type;
    }
}
