<?php

namespace Base\Forge\DependencyInjection;

use Base\Forge\Feature;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The entities of the features that are off (forge.enabled_features) left out of Doctrine's
 * attribute drivers - the auto-mapping reads every file of the bundle's src/Entity, and a
 * file whose parent class is missing (a licence offer is a marketplace product) would stop it.
 */
final class FeatureMappingPass implements CompilerPassInterface
{
    public function __construct(private readonly string $src)
    {
    }

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('forge.enabled_features')) {
            return;
        }

        $enabled = array_map(fn (string $value) => Feature::from($value), $container->getParameter('forge.enabled_features'));
        $off = [];
        foreach (Feature::offPaths($enabled) as $path) {
            if (str_starts_with($path, 'Entity/')) {
                $off[] = rtrim($this->src.'/'.$path, '/');
            }
        }
        if (!$off) {
            return;
        }

        foreach ($container->getDefinitions() as $id => $definition) {
            if (!preg_match('/^doctrine\.orm\.[^.]+_attribute_metadata_driver$/', $id)) {
                continue;
            }
            $paths = (array) ($definition->getArguments()[0] ?? []);
            foreach ($paths as $path) {
                if (\is_string($path) && (realpath($path) ?: rtrim($path, '/')) === (realpath($this->src.'/Entity') ?: $this->src.'/Entity')) {
                    $definition->addMethodCall('addExcludePaths', [$off]);
                    break;
                }
            }
        }
    }
}
