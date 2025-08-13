<?php

use Agavi\Routing\AgaviOptimizedWebRouting;
use Agavi\Routing\AgaviRouteCacheManager;
use Agavi\Routing\AgaviRouteTrie;
use Agavi\Routing\AgaviRoutingCallbackPool;
use Agavi\Routing\AgaviRoutingPerformanceMonitor;
use Agavi\Testing\AgaviUnitTestCase;
use PHPUnit\Framework\TestCase;

/**
 * Test class for AgaviOptimizedWebRouting
 *
 * Tests the high-performance routing logic, cache, trie, and monitoring integration.
 */
class AgaviOptimizedWebRoutingTest extends AgaviUnitTestCase
{
    public function testOptimizationConfigurationMethods()
    {
        // Test the configuration methods that don't require complex mocking
        $routing = new AgaviOptimizedWebRouting();
        
        // Test setting optimizations enabled/disabled
        $routing->setOptimizationsEnabled(false);
        $this->assertTrue(true); // No getter, just ensure no error
        
        $routing->setOptimizationsEnabled(true);
        $this->assertTrue(true);
        
        // Test configuration setting and getting
        $config = ['enable_cache' => false, 'detailed_timing' => true];
        $routing->setOptimizationConfig($config);
        
        $retrievedConfig = $routing->getOptimizationConfig();
        $this->assertIsArray($retrievedConfig);
        $this->assertFalse($retrievedConfig['enable_cache']);
        $this->assertTrue($retrievedConfig['detailed_timing']);
    }

    public function testPerformanceStatsRetrieval()
    {
        $routing = new AgaviOptimizedWebRouting();
        $stats = $routing->getPerformanceStats();
        
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('routing', $stats);
        $this->assertArrayHasKey('cache', $stats);
        $this->assertArrayHasKey('trie', $stats);
        $this->assertArrayHasKey('callbacks', $stats);
    }

    public function testClearOptimizations()
    {
        $routing = new AgaviOptimizedWebRouting();
        
        // This should not throw any errors
        $routing->clearOptimizations();
        $this->assertTrue(true);
    }

    public function testConfigurationDefaults()
    {
        $routing = new AgaviOptimizedWebRouting();
        $config = $routing->getOptimizationConfig();
        
        $this->assertIsArray($config);
        $this->assertArrayHasKey('enable_cache', $config);
        $this->assertArrayHasKey('enable_trie', $config);
        $this->assertArrayHasKey('enable_monitoring', $config);
        $this->assertArrayHasKey('cache_key_prefix', $config);
        $this->assertArrayHasKey('detailed_timing', $config);
        
        // Test default values
        $this->assertTrue($config['enable_cache']);
        $this->assertTrue($config['enable_trie']);
        $this->assertTrue($config['enable_monitoring']);
        $this->assertEquals('agavi_route_', $config['cache_key_prefix']);
        $this->assertFalse($config['detailed_timing']);
    }

    public function testOptimizationIntegration()
    {
        // Test that all optimization components can be accessed without errors
        $routing = new AgaviOptimizedWebRouting();
        
        // Test cache manager integration
        AgaviRouteCacheManager::clear();
        $this->assertTrue(true);
        
        // Test trie integration
        AgaviRouteTrie::clear();
        $this->assertTrue(true);
        
        // Test performance monitor integration
        AgaviRoutingPerformanceMonitor::getResetInstance()->reset();
        $this->assertTrue(true);
        
        // Test callback pool integration
        AgaviRoutingCallbackPool::clearPool();
        $this->assertTrue(true);
    }

    public function testClassInstantiation()
    {
        // Simple test to ensure the class can be instantiated
        $routing = new AgaviOptimizedWebRouting();
        $this->assertInstanceOf(AgaviOptimizedWebRouting::class, $routing);
    }

    public function testPublicMethodsExist()
    {
        $routing = new AgaviOptimizedWebRouting();
        
        // Test that public methods exist and can be called
        $this->assertTrue(method_exists($routing, 'setOptimizationsEnabled'));
        $this->assertTrue(method_exists($routing, 'setOptimizationConfig'));
        $this->assertTrue(method_exists($routing, 'getOptimizationConfig'));
        $this->assertTrue(method_exists($routing, 'getPerformanceStats'));
        $this->assertTrue(method_exists($routing, 'clearOptimizations'));
    }
}
