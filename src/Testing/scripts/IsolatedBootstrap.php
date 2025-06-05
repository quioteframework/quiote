<?php

// Check for isolation settings from either globals (old method) or environment variables (new method)

use Agavi\Config\AgaviConfig;
use Agavi\Testing\AgaviTesting;
use Agavi\Util\AgaviToolkit;

$agaviTestSettings = null;

if (isset($GLOBALS['AGAVI_TESTING_ISOLATED_TEST_SETTINGS'])) {
	// Old method: use globals set by prepareTemplate()
	$agaviTestSettings = $GLOBALS['AGAVI_TESTING_ISOLATED_TEST_SETTINGS'];
	unset($GLOBALS['AGAVI_TESTING_ISOLATED_TEST_SETTINGS']);
} else {
	// Check for persistent cross-process settings first (PHPUnit 12 with process isolation)
	// First try with the unique test identifier
	$uniqueId = $_ENV['AGAVI_ISOLATION_ID'] ?? getenv('AGAVI_ISOLATION_ID');
	$fileFound = false;
	
	if ($uniqueId) {
		$crossProcessFile = sys_get_temp_dir() . '/agavi_isolation_' . $uniqueId;
		if (file_exists($crossProcessFile)) {
			$fileFound = true;
			$settings = json_decode(file_get_contents($crossProcessFile), true);
			if (json_last_error() === JSON_ERROR_NONE && is_array($settings)) {
				$agaviTestSettings = [
					'environment' => $settings['environment'] ?? null,
					'defaultContext' => $settings['defaultContext'] ?? null,
					'clearCache' => !empty($settings['clearCache']),
					'bootstrap' => true,
				];
				
				// Apply these settings to environment for any other process
				if ($agaviTestSettings['environment']) {
					$_ENV['AGAVI_ISOLATION_ENVIRONMENT'] = $agaviTestSettings['environment'];
					putenv('AGAVI_ISOLATION_ENVIRONMENT=' . $agaviTestSettings['environment']);
				}
				if ($agaviTestSettings['defaultContext']) {
					$_ENV['AGAVI_ISOLATION_DEFAULT_CONTEXT'] = $agaviTestSettings['defaultContext'];
					putenv('AGAVI_ISOLATION_DEFAULT_CONTEXT=' . $agaviTestSettings['defaultContext']);
				}
				if ($agaviTestSettings['clearCache']) {
					$_ENV['AGAVI_ISOLATION_CLEAR_CACHE'] = '1';
					putenv('AGAVI_ISOLATION_CLEAR_CACHE=1');
				}
			}
		}
	}
	
	// Fall back to parent PID if no unique ID or file not found
	if (!$fileFound && function_exists('getppid')) {
		$crossProcessFile = sys_get_temp_dir() . '/agavi_isolation_' . getppid();
		if (file_exists($crossProcessFile)) {
			$settings = json_decode(file_get_contents($crossProcessFile), true);
			if (json_last_error() === JSON_ERROR_NONE && is_array($settings)) {
				$agaviTestSettings = [
					'environment' => $settings['environment'] ?? null,
					'defaultContext' => $settings['defaultContext'] ?? null,
					'clearCache' => !empty($settings['clearCache']),
					'bootstrap' => true,
				];
				
				// Apply these settings to environment for any other process
				if ($agaviTestSettings['environment']) {
					$_ENV['AGAVI_ISOLATION_ENVIRONMENT'] = $agaviTestSettings['environment'];
					putenv('AGAVI_ISOLATION_ENVIRONMENT=' . $agaviTestSettings['environment']);
				}
				if ($agaviTestSettings['defaultContext']) {
					$_ENV['AGAVI_ISOLATION_DEFAULT_CONTEXT'] = $agaviTestSettings['defaultContext'];
					putenv('AGAVI_ISOLATION_DEFAULT_CONTEXT=' . $agaviTestSettings['defaultContext']);
				}
				if ($agaviTestSettings['clearCache']) {
					$_ENV['AGAVI_ISOLATION_CLEAR_CACHE'] = '1';
					putenv('AGAVI_ISOLATION_CLEAR_CACHE=1');
				}
			}
		}
	}
	
	// If no cross-process settings, fall back to environment variables
	if (!$agaviTestSettings) {
		$agaviTestSettings = [
			'environment' => $_ENV['AGAVI_ISOLATION_ENVIRONMENT'] ?? getenv('AGAVI_ISOLATION_ENVIRONMENT') ?: null,
			'defaultContext' => $_ENV['AGAVI_ISOLATION_DEFAULT_CONTEXT'] ?? getenv('AGAVI_ISOLATION_DEFAULT_CONTEXT') ?: null,
			'clearCache' => isset($_ENV['AGAVI_ISOLATION_CLEAR_CACHE']) || getenv('AGAVI_ISOLATION_CLEAR_CACHE'),
			'bootstrap' => !isset($_ENV['AGAVI_ISOLATION_NO_BOOTSTRAP']) && !getenv('AGAVI_ISOLATION_NO_BOOTSTRAP'),
		];
	}
}

if($agaviTestSettings['clearCache']) {
	// Clear cache if needed
	$cacheDir = AgaviConfig::get('core.cache_dir');
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

if($agaviTestSettings['bootstrap']) {
	// Bootstrap Agavi directly without loading testing.php (which has PHPUnit compatibility issues)
	\Agavi\Agavi::bootstrap($agaviTestSettings['environment']);
	
	// when agavi is not bootstrapped we don't want / need to load the agavi config
	// values from outside the isolation
	if (isset($GLOBALS['AGAVI_TESTING_CONFIG'])) {
		AgaviConfig::fromArray($GLOBALS['AGAVI_TESTING_CONFIG']);
		unset($GLOBALS['AGAVI_TESTING_CONFIG']);
	}
}

if($agaviTestSettings['clearCache']) {
	AgaviToolkit::clearCache();
}

$env = null;

if($agaviTestSettings['environment']) {
	$env = $agaviTestSettings['environment'];
}

if($agaviTestSettings['bootstrap']) {
	// Direct bootstrap without using AgaviTesting which has PHPUnit compatibility issues
	// Already bootstrapped above, just set the environment flag
}

if($agaviTestSettings['defaultContext']) {
	AgaviConfig::set('core.default_context', $agaviTestSettings['defaultContext']);
}

if(!defined('AGAVI_TESTING_BOOTSTRAPPED')) {
	// when PHPUnit runs with preserve global state enabled, AGAVI_TESTING_BOOTSTRAPPED will already be defined
	define('AGAVI_TESTING_BOOTSTRAPPED', true);
}

// Check for original PHPUnit bootstrap file
$originalBootstrap = null;
if (defined('AGAVI_TESTING_ORIGINAL_PHPUNIT_BOOTSTRAP')) {
	$originalBootstrap = AGAVI_TESTING_ORIGINAL_PHPUNIT_BOOTSTRAP;
} elseif (isset($GLOBALS['__PHPUNIT_BOOTSTRAP'])) {
	$originalBootstrap = $GLOBALS['__PHPUNIT_BOOTSTRAP'];
}

if($originalBootstrap) {
	require_once($originalBootstrap);
}

