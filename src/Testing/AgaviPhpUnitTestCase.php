<?php

// +---------------------------------------------------------------------------+
// | This file is part of the Agavi package.                                   |
// | Copyright (c) 2005-2011 the Agavi Project.                                |
// |                                                                           |
// | For the full copyright and license information, please view the LICENSE   |
// | file that was distributed with this source code. You can also view the    |
// | LICENSE file online at http://www.agavi.org/LICENSE.txt                   |
// |   vi: set noexpandtab:                                                    |
// |   Local Variables:                                                        |
// |   indent-tabs-mode: t                                                     |
// |   End:                                                                    |
// +---------------------------------------------------------------------------+

namespace Agavi\Testing;

use Agavi\Config\AgaviConfig;
use Agavi\Testing\Attributes\AgaviBootstrap;
use Agavi\Testing\Attributes\AgaviClearIsolationCache;
use Agavi\Testing\Attributes\AgaviIsolationDefaultContext;
use Agavi\Testing\Attributes\AgaviIsolationEnvironment;
use Agavi\Util\AgaviToolkit;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * AgaviPhpUnitTestCase is the base class for all Agavi Testcases.
 * 
 * 
 * @package    agavi
 * @subpackage testing
 *
 * @author     Felix Gilcher <felix.gilcher@bitextender.com>
 * @copyright  The Agavi Project
 *
 * @since      1.0.0
 *
 * @version    $Id$
 */
abstract class AgaviPhpUnitTestCase extends TestCase
{
    use AgaviPHPUnitTestCaseMethods;
	/**
	 * @var        string  the name of the environment to bootstrap in isolated tests.
	 */
	protected $isolationEnvironment;
	
	/**
	 * @var        string  the name of the default context to use in isolated tests.
	 */
	protected $isolationDefaultContext;
	
	/**
	 * @var         bool if the cache in the isolated process should be cleared
	 */
	protected $clearIsolationCache = false;
	

	
	
	/**
	 * set the environment to bootstrap in isolated tests
	 * 
	 * @param        string the name of the environment
	 * 
	 * @author       Felix Gilcher <felix.gilcher@bitextender.com>
	 *
	 * @since        1.0.0
	 */
	public function setIsolationEnvironment($environmentName)
	{
		$this->isolationEnvironment = $environmentName;
	}
	
	
	/**
	 * get the environment to bootstrap in isolated tests
	 * 
	 * @return       string the name of the isolation environment
	 * 
	 * @author       Felix Gilcher <felix.gilcher@bitextender.com>
	 *
	 * @since        1.0.2
	 */
	public function getIsolationEnvironment()
	{
		$environmentName = null;
		
		// PHPUnit 12 compatibility: use PHP 8 attributes instead of deprecated getAnnotations()
		try {
			$methodName = $this->name();  // Changed from getName() to name() for PHPUnit 12
			$reflectionMethod = new \ReflectionMethod($this, $methodName);
			
			// Check for method-level attribute
			$attributes = $reflectionMethod->getAttributes(AgaviIsolationEnvironment::class);
			if (!empty($attributes)) {
				$environmentName = $attributes[0]->newInstance()->environment;
			} else {
				// Check for class-level attribute
				$reflectionClass = new \ReflectionClass($this);
				$classAttributes = $reflectionClass->getAttributes(AgaviIsolationEnvironment::class);
				if (!empty($classAttributes)) {
					$environmentName = $classAttributes[0]->newInstance()->environment;
				} elseif (!empty($this->isolationEnvironment)) {
					$environmentName = $this->isolationEnvironment;
				}
			}
		} catch (\Exception) {
			// Fallback to property if reflection fails
			if (!empty($this->isolationEnvironment)) {
				$environmentName = $this->isolationEnvironment;
			}
		}
		
		return $environmentName;
	}
	
	
	/**
	 * set the default context to use in isolated tests
	 * 
	 * @param        string the name of the context
	 * 
	 * @author       Felix Gilcher <felix.gilcher@bitextender.com>
	 *
	 * @since        1.0.2
	 */
	public function setIsolationDefaultContext($contextName)
	{
		$this->isolationDefaultContext = $contextName;
	}
	
	
	/**
	 * get the default context to use in isolated tests
	 * 
	 * @return       string the default context to use in isolated tests
	 * 
	 * @author       Felix Gilcher <felix.gilcher@bitextender.com>
	 *
	 * @since        1.0.2
	 */
	public function getIsolationDefaultContext()
	{
		$ctxName = null;
		
		// PHPUnit 12 compatibility: use PHP 8 attributes instead of deprecated getAnnotations()
		try {
			$reflectionMethod = new \ReflectionMethod($this, $this->name());
			
			// Check for method-level attribute
			$attributes = $reflectionMethod->getAttributes(AgaviIsolationDefaultContext::class);
			if (!empty($attributes)) {
				$ctxName = $attributes[0]->newInstance()->context;
			} else {
				// Check for class-level attribute
				$reflectionClass = new \ReflectionClass($this);
				$classAttributes = $reflectionClass->getAttributes(AgaviIsolationDefaultContext::class);
				if (!empty($classAttributes)) {
					$ctxName = $classAttributes[0]->newInstance()->context;
				} elseif (!empty($this->isolationDefaultContext)) {
					$ctxName = $this->isolationDefaultContext;
				}
			}
		} catch (\Exception) {
			// Fallback to property if reflection fails
			if (!empty($this->isolationDefaultContext)) {
				$ctxName = $this->isolationDefaultContext;
			}
		}
		
		return $ctxName;
	}
	
	
	/**
	 * set whether the cache should be cleared for the isolated subprocess
	 * 
	 * @param        bool true if the cache should be cleared
	 * 
	 * @author       Felix Gilcher <felix.gilcher@bitextender.com>
	 *
	 * @since        1.0.2
	 */
	public function setClearCache($flag)
	{
		$this->clearIsolationCache = (bool)$flag;
	}
	
	
	/**
	 * check whether to clear the cache in isolated tests
	 * 
	 * @return       bool true if the cache is cleared in isolated tests
	 * 
	 * @author       Felix Gilcher <felix.gilcher@bitextender.com>
	 *
	 * @since        1.0.2
	 */
	public function getClearCache()
	{
		$flag = $this->clearIsolationCache;
		
		try {
			$reflectionMethod = new \ReflectionMethod($this, $this->name());
			
			// Check for PHP 8 attribute first
			$attributes = $reflectionMethod->getAttributes(AgaviClearIsolationCache::class);
			if (!empty($attributes)) {
				$flag = true;
			} else {
				// Check class-level attribute
				$reflectionClass = new \ReflectionClass($this);
				$classAttributes = $reflectionClass->getAttributes(AgaviClearIsolationCache::class);
				if (!empty($classAttributes)) {
					$flag = true;
				}
			}
		} catch (\Exception) {
			// Fallback to property if reflection fails
		}
		
		return $flag;
	}
	
	/**
	 * Retrieve the classes and defining files the given class depends on (including the given class)
	 *
	 * @param        ReflectionClass The class to get the dependend classes for.
	 * @param        callable A callback function which takes a file name as argument
	 *                        and returns whether the file is blacklisted.
	 *
	 * @return       string[] An array containing class names as keys and path to the 
	 *                        file's defining class as value.
	 *
	 * @author       Dominik del Bondio <dominik.del.bondio@bitextender.com>
	 * @since        1.1.0
	 */

	
	/**
	 * Set up the test environment. If running with isolation attributes,
	 * bootstrap Agavi with the specified environment.
	 *
	 * @author       Felix Gilcher <felix.gilcher@bitextender.com>
	 * @since        1.0.2
	 */
	protected function setUp(): void
	{
		parent::setUp();

		// Always clear the APCu config cache between tests so compiled configs
		// from one test (e.g. a different locale or environment) don't bleed
		// into the next one running in the same PHP process.
		if (defined('AGAVI_USE_APCU_CONFIG_CACHE') && AGAVI_USE_APCU_CONFIG_CACHE) {
			\Agavi\Config\AgaviAPCuConfigCache::clear();
		}
		
		// Get isolation settings from attributes
		$isolationEnvironment = $this->getIsolationEnvironment();
		$isolationDefaultContext = $this->getIsolationDefaultContext();
		$clearCache = $this->getClearCache();
		
		// If we have isolation settings and are running in a separate process,
		// bootstrap Agavi with the specified environment
		if ($isolationEnvironment && $this->isRunInSeparateProcess()) {
			// Clear cache if requested
			if ($clearCache) {
				AgaviToolkit::clearCache();
			}
			
			// Set the testing environment configuration before bootstrap
			AgaviConfig::set('testing.environment', $isolationEnvironment, true, true);
			
			// Set default context if specified
			if ($isolationDefaultContext) {
				AgaviConfig::set('core.default_context', $isolationDefaultContext, true, true);
			}
			
			// Bootstrap Agavi with the isolation environment
			\Agavi\Agavi::bootstrap($isolationEnvironment);
		} elseif (!AgaviConfig::get('core.environment')) {
			// Non-isolated test and Agavi not yet bootstrapped - bootstrap with default testing environment
			\Agavi\Agavi::bootstrap('testing');
		}
	}
	
	/**
	 * Check if this test method should run in a separate process
	 * by looking for the RunInSeparateProcess attribute on method or class
	 *
	 * @return bool
	 */
	private function isRunInSeparateProcess(): bool
	{
		try {
			// Check method-level attribute first
			$reflectionMethod = new \ReflectionMethod($this, $this->name());
			$methodAttributes = $reflectionMethod->getAttributes(\PHPUnit\Framework\Attributes\RunInSeparateProcess::class);
			if (!empty($methodAttributes)) {
				return true;
			}
			
			// Check class-level attributes
			$reflectionClass = new \ReflectionClass($this);
			$classAttributes = $reflectionClass->getAttributes(\PHPUnit\Framework\Attributes\RunInSeparateProcess::class);
			if (!empty($classAttributes)) {
				return true;
			}
			
			// Also check for RunTestsInSeparateProcesses attribute on class
			$runTestsAttributes = $reflectionClass->getAttributes(\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses::class);
			return !empty($runTestsAttributes);
			
		} catch (\Exception) {
			return false;
		}
	}
	
	/**
	 * Clean up after the test has run
	 */
	protected function tearDown(): void
	{
		parent::tearDown();
		// Nothing special needed for cleanup in our simplified approach
	}
	
	/**
	 * Whether or not an agavi bootstrap should be done in isolation.
	 * 
	 * @return       boolean true if agavi should be bootstrapped
	 * 
	 * @author       Felix Gilcher <felix.gilcher@bitextender.com>
	 *
	 * @since        1.0.2
	 */
	protected function doBootstrap()
	{
		$flag = true;
		
		try {
			$reflectionMethod = new \ReflectionMethod($this, $this->name());
			
			// Check for PHP 8 attribute first
			$attributes = $reflectionMethod->getAttributes(AgaviBootstrap::class);
			if (!empty($attributes)) {
				$attribute = $attributes[0]->newInstance();
				$flag = $attribute->bootstrap;
			} else {
				// Check class-level attribute
				$reflectionClass = new \ReflectionClass($this);
				$classAttributes = $reflectionClass->getAttributes(AgaviBootstrap::class);
				if (!empty($classAttributes)) {
					$attribute = $classAttributes[0]->newInstance();
					$flag = $attribute->bootstrap;
				}
			}
		} catch (\Exception) {
			// Keep default flag = true if reflection fails
		}
		
		return $flag;
	}
	
}
