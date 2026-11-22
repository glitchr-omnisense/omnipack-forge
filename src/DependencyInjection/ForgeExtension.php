<?php

namespace Base\Forge\DependencyInjection;

use Base\Bundle\AbstractBaseExtension;
use Base\Forge\Feature;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

class ForgeExtension extends AbstractBaseExtension
{
    public function getConfiguration(array $config, ContainerBuilder $container): ForgeConfiguration
    {
        return new ForgeConfiguration();
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new ForgeConfiguration();
        $config = (new Processor())->processConfiguration($configuration, $configs);

        // The features on (Base\Forge\Feature): their packages installed, none turned off. Read by
        // services.php (what it registers), ForgeRouteLoader (the routes), ForgeBundle's mapping
        // pass (the entities) and the templates (forge_feature()).
        $container->setParameter('forge.enabled_features', array_map(fn (Feature $feature) => $feature->value, Feature::enabled($config['features'] ?? [])));

        $loader = new PhpFileLoader($container, new FileLocator(\dirname(__DIR__, 2).'/config'));
        $loader->load('services.php');

        // forge.storage_dir, forge.download_ttl, forge.hourly_rate...
        $this->setConfiguration($container, $config, $configuration->getConfigTreeBuilder()->buildTree()->getName());
    }
}
