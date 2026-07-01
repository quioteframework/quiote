<?php

// Check for isolation settings from either globals (old method) or environment variables (new method)

use Quiote\Config\Config;
use Quiote\Testing\Testing;
use Quiote\Util\Toolkit;

$quioteTestSettings = null;

if (isset($GLOBALS['QUIOTE_TESTING_ISOLATED_TEST_SETTINGS'])) {
	// Old method: use globals set by prepareTemplate()
	$quioteTestSettings = $GLOBALS['QUIOTE_TESTING_ISOLATED_TEST_SETTINGS'];
	unset($GLOBALS['QUIOTE_TESTING_ISOLATED_TEST_SETTINGS']);
} else {
	// Check for persistent cross-process settings first (PHPUnit 12 with process isolation)
	// First try with the unique test identifier
	$uniqueId = $_ENV['QUIOTE_ISOLATION_ID'] ?? getenv('QUIOTE_ISOLATION_ID');
	$fileFound = false;
	
	if ($uniqueId) {
		$crossProcessFile = sys_get_temp_dir() . '/quiote_isolation_' . $uniqueId;
		if (file_exists($crossProcessFile)) {
			$fileFound = true;
			$settings = json_decode(file_get_contents($crossProcessFile), true);
			if (json_last_error() === JSON_ERROR_NONE && is_array($settings)) {
				$quioteTestSettings = [
					'environment' => $settings['environment'] ?? null,
					'defaultContext' => $settings['defaultContext'] ?? null,
					'clearCache' => !empty($settings['clearCache']),
					'bootstrap' => true,
				];
				
				// Apply these settings to environment for any other process
				if ($quioteTestSettings['environment']) {
					$_ENV['QUIOTE_ISOLATION_ENVIRONMENT'] = $quioteTestSettings['environment'];
					putenv('QUIOTE_ISOLATION_ENVIRONMENT=' . $quioteTestSettings['environment']);
				}
				if ($quioteTestSettings['defaultContext']) {
					$_ENV['QUIOTE_ISOLATION_DEFAULT_CONTEXT'] = $quioteTestSettings['defaultContext'];
					putenv('QUIOTE_ISOLATION_DEFAULT_CONTEXT=' . $quioteTestSettings['defaultContext']);
				}
				if ($quioteTestSettings['clearCache']) {
					$_ENV['QUIOTE_ISOLATION_CLEAR_CACHE'] = '1';
					putenv('QUIOTE_ISOLATION_CLEAR_CACHE=1');
				}
			}
		}
	}
	
	// Fall back to parent PID if no unique ID or file not found
	if (!$fileFound && function_exists('getppid')) {
		$crossProcessFile = sys_get_temp_dir() . '/quiote_isolation_' . getppid();
		if (file_exists($crossProcessFile)) {
			$settings = json_decode(file_get_contents($crossProcessFile), true);
			if (json_last_error() === JSON_ERROR_NONE && is_array($settings)) {
				$quioteTestSettings = [
					'environment' => $settings['environment'] ?? null,
					'defaultContext' => $settings['defaultContext'] ?? null,
					'clearCache' => !empty($settings['clearCache']),
					'bootstrap' => true,
				];
				
				// Apply these settings to environment for any other process
				if ($quioteTestSettings['environment']) {
					$_ENV['QUIOTE_ISOLATION_ENVIRONMENT'] = $quioteTestSettings['environment'];
					putenv('QUIOTE_ISOLATION_ENVIRONMENT=' . $quioteTestSettings['environment']);
				}
				if ($quioteTestSettings['defaultContext']) {
					$_ENV['QUIOTE_ISOLATION_DEFAULT_CONTEXT'] = $quioteTestSettings['defaultContext'];
					putenv('QUIOTE_ISOLATION_DEFAULT_CONTEXT=' . $quioteTestSettings['defaultContext']);
				}
				if ($quioteTestSettings['clearCache']) {
					$_ENV['QUIOTE_ISOLATION_CLEAR_CACHE'] = '1';
					putenv('QUIOTE_ISOLATION_CLEAR_CACHE=1');
				}
			}
		}
	}
	
	// If no cross-process settings, fall back to environment variables
	if (!$quioteTestSettings) {
		$quioteTestSettings = [
			'environment' => $_ENV['QUIOTE_ISOLATION_ENVIRONMENT'] ?? getenv('QUIOTE_ISOLATION_ENVIRONMENT') ?: null,
			'defaultContext' => $_ENV['QUIOTE_ISOLATION_DEFAULT_CONTEXT'] ?? getenv('QUIOTE_ISOLATION_DEFAULT_CONTEXT') ?: null,
			'clearCache' => isset($_ENV['QUIOTE_ISOLATION_CLEAR_CACHE']) || getenv('QUIOTE_ISOLATION_CLEAR_CACHE'),
			'bootstrap' => !isset($_ENV['QUIOTE_ISOLATION_NO_BOOTSTRAP']) && !getenv('QUIOTE_ISOLATION_NO_BOOTSTRAP'),
		];
	}
}

if($quioteTestSettings['clearCache']) {
	// Clear cache if needed
	$cacheDir = Config::get('core.cache_dir');
	if($cacheDir && is_dir($cacheDir)) {
		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($cacheDir, RecursiveDirectoryIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach($files as $fileinfo) {
			if($fileinfo->isDir()) {
				rmdir($fileinfo->getRealPath());
			} else {
				unlink($fileinfo->getRealPath());
			}
		}
	}
}

if($quioteTestSettings['bootstrap']) {
	// Bootstrap Quiote directly without loading testing.php (which has PHPUnit compatibility issues)
	\Quiote\Quiote::bootstrap($quioteTestSettings['environment']);
	
	// when quiote is not bootstrapped we don't want / need to load the quiote config
	// values from outside the isolation
	if (isset($GLOBALS['QUIOTE_TESTING_CONFIG'])) {
		Config::fromArray($GLOBALS['QUIOTE_TESTING_CONFIG']);
		unset($GLOBALS['QUIOTE_TESTING_CONFIG']);
	}
}

if($quioteTestSettings['clearCache']) {
	Toolkit::clearCache();
}

$env = null;

if($quioteTestSettings['environment']) {
	$env = $quioteTestSettings['environment'];
}

if($quioteTestSettings['bootstrap']) {
	// Direct bootstrap without using Testing which has PHPUnit compatibility issues
	// Already bootstrapped above, just set the environment flag
}

if($quioteTestSettings['defaultContext']) {
	Config::set('core.default_context', $quioteTestSettings['defaultContext']);
}

if(!defined('QUIOTE_TESTING_BOOTSTRAPPED')) {
	// when PHPUnit runs with preserve global state enabled, QUIOTE_TESTING_BOOTSTRAPPED will already be defined
	define('QUIOTE_TESTING_BOOTSTRAPPED', true);
}

// Check for original PHPUnit bootstrap file
$originalBootstrap = null;
if (defined('QUIOTE_TESTING_ORIGINAL_PHPUNIT_BOOTSTRAP')) {
	$originalBootstrap = QUIOTE_TESTING_ORIGINAL_PHPUNIT_BOOTSTRAP;
} elseif (isset($GLOBALS['__PHPUNIT_BOOTSTRAP'])) {
	$originalBootstrap = $GLOBALS['__PHPUNIT_BOOTSTRAP'];
}

if($originalBootstrap) {
	require_once($originalBootstrap);
}

