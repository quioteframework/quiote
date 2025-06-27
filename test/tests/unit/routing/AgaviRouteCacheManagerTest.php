<?php

use Agavi\Routing\AgaviRouteCacheManager;
use Agavi\Testing\AgaviUnitTestCase;

/**
 * Test class for AgaviRouteCacheManager
 */
class AgaviRouteCacheManagerTest extends AgaviUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Clear cache before each test
        AgaviRouteCacheManager::clear();
    }
    
    protected function tearDown(): void
    {
        // Clear cache after each test
        AgaviRouteCacheManager::clear();
        parent::tearDown();
    }
    
    /**
     * Test basic cache operations
     */
    public function testBasicCacheOperations()
    {
        $key = 'test_key';
        $value = ['module' => 'TestModule', 'action' => 'TestAction'];
        
        // Test cache miss
        $this->assertNull(AgaviRouteCacheManager::get($key));
        
        // Test cache set and get
        AgaviRouteCacheManager::set($key, $value);
        $this->assertEquals($value, AgaviRouteCacheManager::get($key));
        
        // Test cache hit statistics
        $stats = AgaviRouteCacheManager::getStats();
        $this->assertEquals(1, $stats['hits']);
        $this->assertEquals(1, $stats['misses']);
        $this->assertEquals(0.5, $stats['hit_ratio']);
        $this->assertEquals(1, $stats['size']);
    }
    
    /**
     * Test cache eviction with maximum size
     */
    public function testCacheEviction()
    {
        // Set a small cache size for testing
        AgaviRouteCacheManager::setMaxSize(3);
        
        // Fill cache to capacity
        AgaviRouteCacheManager::set('key1', ['data' => 'value1']);
        AgaviRouteCacheManager::set('key2', ['data' => 'value2']);
        AgaviRouteCacheManager::set('key3', ['data' => 'value3']);
        
        $this->assertEquals(3, AgaviRouteCacheManager::getSize());
        
        // Adding another item should evict the first (FIFO)
        AgaviRouteCacheManager::set('key4', ['data' => 'value4']);
        
        $this->assertEquals(3, AgaviRouteCacheManager::getSize());
        $this->assertNull(AgaviRouteCacheManager::get('key1')); // Should be evicted
        $this->assertNotNull(AgaviRouteCacheManager::get('key2')); // Should still exist
        $this->assertNotNull(AgaviRouteCacheManager::get('key3')); // Should still exist
        $this->assertNotNull(AgaviRouteCacheManager::get('key4')); // Should exist
    }
    
    /**
     * Test cache statistics
     */
    public function testCacheStatistics()
    {
        $stats = AgaviRouteCacheManager::getStats();
        $this->assertEquals(0, $stats['hits']);
        $this->assertEquals(0, $stats['misses']);
        $this->assertEquals(0, $stats['hit_ratio']);
        $this->assertEquals(0, $stats['size']);
        
        // Generate some cache activity
        AgaviRouteCacheManager::get('nonexistent'); // miss
        AgaviRouteCacheManager::set('key', 'value');
        AgaviRouteCacheManager::get('key'); // hit
        AgaviRouteCacheManager::get('key'); // hit
        
        $stats = AgaviRouteCacheManager::getStats();
        $this->assertEquals(2, $stats['hits']);
        $this->assertEquals(1, $stats['misses']);
        $this->assertEquals(2/3, $stats['hit_ratio']);
        $this->assertEquals(1, $stats['size']);
    }
    
    /**
     * Test cache clearing
     */
    public function testCacheClear()
    {
        AgaviRouteCacheManager::set('key1', 'value1');
        AgaviRouteCacheManager::set('key2', 'value2');
        AgaviRouteCacheManager::get('key1'); // Generate some stats
        
        $this->assertEquals(2, AgaviRouteCacheManager::getSize());
        
        AgaviRouteCacheManager::clear();
        
        $this->assertEquals(0, AgaviRouteCacheManager::getSize());
        $this->assertNull(AgaviRouteCacheManager::get('key1'));
        $this->assertNull(AgaviRouteCacheManager::get('key2'));
        
        // Stats should also be cleared
        $stats = AgaviRouteCacheManager::getStats();
        $this->assertEquals(0, $stats['hits']);
        $this->assertEquals(2, $stats['misses']); // The two get() calls above should count as misses
    }
    
    /**
     * Test cache size configuration
     */
    public function testCacheSizeConfiguration()
    {
        $originalSize = 5000; // Default size
        AgaviRouteCacheManager::setMaxSize(10);
        
        // Fill beyond the new limit
        for ($i = 0; $i < 15; $i++) {
            AgaviRouteCacheManager::set("key{$i}", "value{$i}");
        }
        
        $this->assertEquals(10, AgaviRouteCacheManager::getSize());
        
        // First items should be evicted
        $this->assertNull(AgaviRouteCacheManager::get('key0'));
        $this->assertNull(AgaviRouteCacheManager::get('key4'));
        $this->assertNotNull(AgaviRouteCacheManager::get('key14'));
    }
    
    /**
     * Test complex cache values
     */
    public function testComplexCacheValues()
    {
        $complexValue = [
            'module' => 'UserModule',
            'action' => 'ProfileAction',
            'parameters' => [
                'user_id' => 123,
                'profile_type' => 'public',
                'nested' => ['key' => 'value']
            ],
            'metadata' => [
                'timestamp' => time(),
                'source' => 'optimized_routing'
            ]
        ];
        
        AgaviRouteCacheManager::set('complex_route', $complexValue);
        $retrieved = AgaviRouteCacheManager::get('complex_route');
        
        $this->assertEquals($complexValue, $retrieved);
        $this->assertEquals('UserModule', $retrieved['module']);
        $this->assertEquals(123, $retrieved['parameters']['user_id']);
        $this->assertEquals('value', $retrieved['parameters']['nested']['key']);
    }
}
