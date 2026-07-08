<?php

require dirname(__DIR__, 3) . '/vendor/autoload.php';

Quiote\Runtime\Kernel::create([
    'app_dir' => dirname(__DIR__) . '/app',
    'env' => getenv('QUIOTE_ENV') ?: 'development',
    'context' => 'web',
])->run();

?>