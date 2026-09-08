<?php
/**
 * Fallback PSR-4 autoloader for the Flavor\TagPilot\ namespace.
 *
 * PrestaShop includes this file from AppKernel::enableComposerAutoloaderOnModules()
 * before the Symfony container is compiled:
 *
 *     $autoloader = $moduleDirectoryPath . $module . '/vendor/autoload.php';
 *     if (file_exists($autoloader)) {
 *         include_once $autoloader;
 *     }
 *
 * Without it, the services declared in config/services.yml cannot be resolved and
 * container compilation throws, which takes down every back office page with an
 * HTTP 500 -- not only TagPilot's own screens.
 *
 * This file is intentionally dependency-free and mirrors the "autoload.psr-4"
 * mapping of composer.json. Running `composer install` in this directory replaces
 * it with Composer's generated autoloader, so shops and CI builds that do run
 * Composer keep the optimized classmap.
 *
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'Flavor\\TagPilot\\';
    $length = strlen($prefix);

    if (strncmp($prefix, $class, $length) !== 0) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, $length));

    // Defensive: never let a crafted class name escape src/.
    if ($relative === '' || strpos($relative, '..') !== false) {
        return;
    }

    $file = __DIR__ . '/../src/' . $relative . '.php';

    if (is_file($file)) {
        require $file;
    }
});
