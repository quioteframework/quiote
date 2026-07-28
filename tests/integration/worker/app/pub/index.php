<?php

// Front controller for the probe app: lets the same endpoints be exercised under
// a plain SAPI, which is the control the off-SAPI assertions are compared against.
spl_autoload_register(static function (string $class): void {
    $prefix = 'WorkerProbe\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = dirname(__DIR__) . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require dirname(__DIR__, 5) . '/vendor/autoload.php';

Quiote\Runtime\Kernel::create([
    'app_dir' => dirname(__DIR__),
    'env' => 'production',
    'context' => 'web',
])->run();
