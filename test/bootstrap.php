<?php

// Bootstrap file for PHPUnit 12 testing

// Load Composer autoloader first
require_once(__DIR__ . '/../vendor/autoload.php');

// Set up basic configuration - MUST HAPPEN BEFORE BOOTSTRAP
$testDir = realpath(__DIR__);
$appDir = realpath(__DIR__ . '/sandbox/app/');
$srcDir = realpath(__DIR__ . '/../src/');
\Agavi\Config\AgaviConfig::set('core.testing_dir', $testDir);
\Agavi\Config\AgaviConfig::set('core.app_dir', $appDir);
\Agavi\Config\AgaviConfig::set('core.config_dir', $appDir . '/Config/');
\Agavi\Config\AgaviConfig::set('core.cache_dir', $appDir . '/cache');
\Agavi\Config\AgaviConfig::set('core.system_config_dir', $srcDir . '/Config/defaults/');
\Agavi\Config\AgaviConfig::set('core.default_context', 'testing');
\Agavi\Config\AgaviConfig::set('testing.environment', 'testing');
// Set the namespace prefix for the test environment before any bootstrapping
\Agavi\Config\AgaviConfig::set('core.namespace_prefix', 'Sandbox');

// Required for both autoloaded and non-autoloaded classes
// Only load Agavi.php if we're not in an isolated test
// Isolated tests (like AgaviConfigTest) will load what they need directly
if (!isset($_ENV['AGAVI_ISOLATED_TEST']) && !getenv('AGAVI_ISOLATED_TEST')) {
    require_once(__DIR__ . '/../src/Agavi.php');
}

// Simple bootstrap approach for PHPUnit 12
// Test cases will handle bootstrapping themselves either:
// 1. Via AgaviTesting::bootstrap() for normal tests
// 2. Via their setUp() method for isolated tests with RunInSeparateProcess attributes

// Do not bootstrap here as it interferes with isolation tests