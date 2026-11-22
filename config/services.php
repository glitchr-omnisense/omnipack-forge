<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

/*
 * This file is part of the Glitchr package.
 *
 * (c) Marco Meyer <marco.meyer@glitchr.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Base\Forge\Feature;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/*
 * Plain autowiring over src/, as base-bundle-market does. Entities, enums,
 * models and the backends' statements are not services; the admin CRUD controllers are loaded only when
 * base-bundle-admin is there. A feature that is off (Base\Forge\Feature: its package missing, or
 * turned off in forge.features) leaves its files out: their classes may not even load without it.
 */
return function (ContainerConfigurator $configurator, ContainerBuilder $container) {

    $src = dirname(__DIR__).'/src';

    $enabled = array_map(fn (string $value) => Feature::from($value), $container->getParameter('forge.enabled_features'));
    $off = Feature::offPaths($enabled);
    // The files left out under a folder of src/ (Symfony wants the excluded paths inside the loaded one).
    $offUnder = static fn (string $folder): array => array_values(array_map(
        fn (string $path) => $src.'/'.$path,
        array_filter($off, fn (string $path) => '' === $folder || str_starts_with($path, $folder)),
    ));

    $services = $configurator->services();
    $services->defaults()
        ->autowire(true)
        ->autoconfigure(true)
        ->public(false);

    $services->load('Base\\Forge\\', $src.'/')
        ->exclude(array_merge([
            $src.'/DependencyInjection/',
            $src.'/Entity/',
            $src.'/Enum/',
            $src.'/Model/',
            $src.'/Exception/',
            $src.'/Controller/',
            $src.'/Feature.php',
            $src.'/ForgeBundle.php',
        ], array_filter($offUnder(''), fn (string $path) => !str_starts_with($path, $src.'/Controller/') && !str_starts_with($path, $src.'/Entity/'))));

    $services->load('Base\\Forge\\Controller\\Client\\', $src.'/Controller/Client/')
        ->exclude($offUnder('Controller/Client/'))
        ->tag('controller.service_arguments');

    if (class_exists('Base\\Admin\\Controller\\AbstractCrudController') && is_dir($src.'/Controller/Admin')) {
        $services->load('Base\\Forge\\Controller\\Admin\\', $src.'/Controller/Admin/')
            ->exclude($offUnder('Controller/Admin/'))
            ->tag('controller.service_arguments');
    }

    // Symfony keeps an excluded class as an abstract definition (container.excluded), which a pass
    // may still load (class_exists): the classes of the features that are off leave the container.
    foreach ($off as $path) {
        $files = str_ends_with($path, '/') ? (glob($src.'/'.$path.'{,*/}*.php', \GLOB_BRACE) ?: []) : [$src.'/'.$path];
        foreach ($files as $file) {
            $container->removeDefinition('Base\\Forge\\'.str_replace('/', '\\', substr($file, \strlen($src) + 1, -4)));
        }
    }
};
