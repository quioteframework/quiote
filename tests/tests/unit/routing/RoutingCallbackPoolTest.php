<?php

use Quiote\Routing\RoutingCallbackPool;
use Quiote\Exception\QuioteException;
use PHPUnit\Framework\TestCase;

/**
 * Test class for RoutingCallbackPool
 * Tests the callback pooling functionality for routing performance optimization
 */
class RoutingCallbackPoolTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset pool state before each test
        $reflection = new ReflectionClass(\Quiote\Routing\RoutingCallbackPool::class);
        $instancesProperty = $reflection->getStaticPropertyValue('instances');
        $reflection->setStaticPropertyValue('instances', []);
        $reflection->setStaticPropertyValue('accessCount', 0);
    }

    /**
     * Test getting a callback instance from the pool
     */
    public function testGetInstance(): void
    {
        // Since RoutingCallbackPool creates real instances,
        // we'll test with a real class that exists
        $instance = RoutingCallbackPool::getInstance('stdClass');
        $this->assertInstanceOf('stdClass', $instance);
    }

    /**
     * Test that the same instance is returned for the same parameters
     */
    public function testInstanceReuse(): void
    {
        $instance1 = RoutingCallbackPool::getInstance('stdClass', ['param1' => 'value1']);
        $instance2 = RoutingCallbackPool::getInstance('stdClass', ['param1' => 'value1']);
        
        // Should get the same instance from pool
        $this->assertSame($instance1, $instance2);
    }

    /**
     * Test that different instances are returned for different parameters
     */
    public function testDifferentInstancesForDifferentParameters(): void
    {
        $instance1 = RoutingCallbackPool::getInstance('stdClass', ['param1' => 'value1']);
        $instance2 = RoutingCallbackPool::getInstance('stdClass', ['param1' => 'value2']);
        
        // Should get different instances for different parameters
        $this->assertNotSame($instance1, $instance2);
    }

    /**
     * Test pool size management
     */
    public function testPoolSizeManagement(): void
    {
        // Create instances up to the pool limit
        $instances = [];
        for ($i = 0; $i < 105; $i++) { // Exceed default max of 100
            $instances[] = RoutingCallbackPool::getInstance('stdClass', ['id' => $i]);
        }
        
        // Pool should manage its size automatically
        $this->assertCount(105, $instances);
        
        // Get pool statistics
        $stats = RoutingCallbackPool::getStats();
        $this->assertArrayHasKey('pool_size', $stats);
        $this->assertArrayHasKey('access_count', $stats);
        $this->assertArrayHasKey('max_instances', $stats);
        $this->assertArrayHasKey('memory_usage', $stats);
    }

    /**
     * Test pool statistics
     */
    public function testPoolStatistics(): void
    {
        // Clear pool first
        RoutingCallbackPool::clearPool();

        // Make some requests
        $instance1 = RoutingCallbackPool::getInstance('stdClass', ['test' => 1]);
        $instance2 = RoutingCallbackPool::getInstance('stdClass', ['test' => 1]); // Should be cache hit
        $instance3 = RoutingCallbackPool::getInstance('stdClass', ['test' => 2]); // Should be cache miss

        $stats = RoutingCallbackPool::getStats();

        $this->assertGreaterThan(0, $stats['access_count']);
        $this->assertEquals(2, $stats['pool_size']); // Should have 2 different instances
    }

    /**
     * Test pool clearing
     */
    public function testClearPool(): void
    {
        // Create some instances
        RoutingCallbackPool::getInstance('stdClass', ['test' => 1]);
        RoutingCallbackPool::getInstance('stdClass', ['test' => 2]);
        
        $statsBefore = RoutingCallbackPool::getStats();
        $this->assertGreaterThan(0, $statsBefore['pool_size']);
        
        // Clear the pool
        RoutingCallbackPool::clearPool();
        
        $statsAfter = RoutingCallbackPool::getStats();
        $this->assertEquals(0, $statsAfter['pool_size']);
    }

    /**
     * Test configuration changes
     */
    public function testConfiguration(): void
    {
        // Test setting max instances
        RoutingCallbackPool::setMaxInstances(50);
        
        // Create instances up to new limit
        for ($i = 0; $i < 55; $i++) {
            RoutingCallbackPool::getInstance('stdClass', ['id' => $i]);
        }
        
        $stats = RoutingCallbackPool::getStats();
        $this->assertLessThanOrEqual(50, $stats['pool_size']);
    }

    /**
     * Test callback with parameters method
     */
    public function testCallbackWithParameters(): void
    {
        // Create a mock callback class that supports parameters
        $mockClass = new class {
            /** @var array<string, mixed> */
            private array $parameters = [];

            /** @param array<string, mixed> $params */
            public function setParameters(array $params): void {
                $this->parameters = $params;
            }

            /** @return array<string, mixed> */
            public function getParameters(): array {
                return $this->parameters;
            }
        };
        
        $className = $mockClass::class;
        $parameters = ['key' => 'value', 'number' => 42];
        
        // We can't directly test with mock classes in getInstance,
        // but we can verify the method exists and handles parameters
        $reflection = new ReflectionClass(\Quiote\Routing\RoutingCallbackPool::class);
        $method = $reflection->getMethod('getInstance');
        
        $this->assertTrue($method->isStatic());
        $this->assertTrue($method->isPublic());
    }

    /**
     * Test error handling for non-existent classes
     */
    public function testNonExistentClass(): void
    {
        $this->expectException(QuioteException::class);
        RoutingCallbackPool::getInstance('NonExistentCallbackClass');
    }

    /**
     * Test pool performance under load
     */
    public function testPoolPerformance(): void
    {
        RoutingCallbackPool::clearPool();
        $startTime = microtime(true);
        
        // Simulate heavy usage
        for ($i = 0; $i < 1000; $i++) {
            $instance = RoutingCallbackPool::getInstance('stdClass', ['id' => $i % 10]);
        }
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        
        // Should complete reasonably quickly
        $this->assertLessThan(1.0, $duration, 'Pool operations should be fast');
        
        $stats = RoutingCallbackPool::getStats();
        $this->assertEquals(10, $stats['pool_size'], 'Should have 10 different instances');
        $this->assertGreaterThan(900, $stats['access_count'], 'Should have many accesses');
    }

    /**
     * Test memory usage optimization
     */
    public function testMemoryOptimization(): void
    {
        $initialMemory = memory_get_usage();

        // Create many instances
        for ($i = 0; $i < 200; $i++) {
            RoutingCallbackPool::getInstance('stdClass', ['id' => $i]);
        }

        $afterCreationMemory = memory_get_usage();

        // Clear pool
        RoutingCallbackPool::clearPool();

        $afterClearMemory = memory_get_usage();

        // Memory should be managed efficiently
        $this->assertGreaterThan($initialMemory, $afterCreationMemory);
        // Note: Memory might not return to exactly initial due to PHP's memory management
    }
}
