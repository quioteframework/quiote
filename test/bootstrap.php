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
// Mirror the filesystem directives Agavi::bootstrap() sets. Some tests extend
// plain PHPUnit\Framework\TestCase (not AgaviPhpUnitTestCase) and never call
// Agavi::bootstrap(), yet still touch AgaviContext (e.g. via the debug logger),
// which lazily compiles config_handlers.xml. The handler patterns there contain
// %core.module_dir% etc.; if those directives are unset at compile time the
// placeholders are baked literally into the shared static handler map and never
// match, poisoning every later test in the process. Defining them up-front (and
// not read-only, so a later Agavi::bootstrap() can still set its own) guarantees
// the placeholders always resolve regardless of test execution order.
\Agavi\Config\AgaviConfig::set('core.module_dir', $appDir . '/Modules');
\Agavi\Config\AgaviConfig::set('core.model_dir', $appDir . '/Models');
\Agavi\Config\AgaviConfig::set('core.lib_dir', $appDir . '/Lib');
\Agavi\Config\AgaviConfig::set('core.template_dir', $appDir . '/Templates');
\Agavi\Config\AgaviConfig::set('core.default_context', 'testing');
\Agavi\Config\AgaviConfig::set('testing.environment', 'testing');
// Disable CSRF enforcement in the test environment (as Symfony and others do):
// otherwise every POST/PUT/DELETE functional test would need to carry a valid
// token. The dedicated CsrfTest re-enables it per test to exercise the feature.
\Agavi\Config\AgaviConfig::set('core.csrf.enabled', false);
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