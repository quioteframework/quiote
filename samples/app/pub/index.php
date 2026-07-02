<?php

/**
 * Front controller / FrankenPHP worker entrypoint.
 *
 * Self-contained: registers its own PSR-4-ish autoloader for the app's own
 * namespace so this app doesn't need its own composer.json, then finds
 * *some* vendor/autoload.php (walking up from here) to pull in Quiote
 * itself and its dependencies.
 */

spl_autoload_register(static function (string $class): void {
	$prefix = 'SampleApp\\';
	if (!str_starts_with($class, $prefix)) {
		return;
	}
	$relative = substr($class, strlen($prefix));
	$file = dirname(__DIR__) . '/' . str_replace('\\', '/', $relative) . '.php';
	if (is_file($file)) {
		require $file;
	}
});

$autoloadCandidates = [
	
	dirname(__DIR__) . '/vendor/autoload.php',
	dirname(__DIR__, 2) . '/vendor/autoload.php',
	dirname(__DIR__, 3) . '/vendor/autoload.php',
	dirname(__DIR__, 4) . '/vendor/autoload.php',
	dirname(__DIR__, 5) . '/vendor/autoload.php',
];
foreach ($autoloadCandidates as $candidate) {
	if (is_file($candidate)) {
		require $candidate;
		break;
	}
}
if (!class_exists(Quiote\Runtime\Kernel::class)) {
	error_log('Could not find a vendor/autoload.php with quioteframework/quiote installed.');
	http_response_code(500);
	exit(1);
}

Quiote\Runtime\Kernel::create([
	'app_dir' => dirname(__DIR__),
	'env' => getenv('QUIOTE_ENV') ?: 'development',
	'context' => 'web',
])->run();
