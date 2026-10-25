<?php

declare(strict_types=1);

/**
 * Minimal PSR-4 autoloader for running KiBot without Composer
 * (standalone endpoint, CLI, tests). With Composer, vendor/autoload.php does the same.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Z77\\KiBot\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
