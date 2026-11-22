<?php

namespace Tests\Base\Forge\Fixtures;

/**
 * The forge's optional packages made absent from this process: their classes no longer load,
 * as in an application that did not install them. The host's Composer autoloader is wrapped,
 * every other class still found by it. For a test run in its own process (CoreKernelTest).
 */
final class WithoutOptionalPackages
{
    /** The namespaces of omnipack/marketplace, git/git-bundle, omnisense/omnihub, omnisense/omnistate, omnisense/omnitrade - and the host's own App\, which may stand on them. */
    public const NAMESPACES = ['Base\\Marketplace\\', 'Git\\', 'Omnihub\\', 'Omnistate\\', 'Omnitrade\\', 'Omnibus\\', 'App\\'];

    private static bool $installed = false;

    public static function install(): void
    {
        if (self::$installed) {
            return;
        }
        self::$installed = true;

        foreach (self::NAMESPACES as $namespace) {
            foreach (array_merge(get_declared_classes(), get_declared_interfaces(), get_declared_traits()) as $declared) {
                if (str_starts_with($declared, $namespace) && 'App\\' !== $namespace) {
                    throw new \LogicException(sprintf('%s is already loaded: run the test in its own process.', $declared));
                }
            }
        }

        foreach (spl_autoload_functions() as $loader) {
            if (!\is_array($loader) || !$loader[0] instanceof \Composer\Autoload\ClassLoader) {
                continue;
            }
            spl_autoload_unregister($loader);
            spl_autoload_register(static function (string $class) use ($loader): void {
                foreach (self::NAMESPACES as $namespace) {
                    if (str_starts_with($class, $namespace)) {
                        return;
                    }
                }
                $loader($class);
            }, true, true);
        }
    }
}
