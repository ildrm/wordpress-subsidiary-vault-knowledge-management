<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'SubsidiaryVault\\')) {
        $file = __DIR__ . '/' . substr($class, strlen('SubsidiaryVault\\')) . '.php';
        if (is_file($file)) { require $file; }
    }
});
