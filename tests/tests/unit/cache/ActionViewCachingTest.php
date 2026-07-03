<?php

use PHPUnit\Framework\TestCase;
use Quiote\Cache\ActionViewCache;
use Quiote\Cache\CacheManager;
use Quiote\Config\Config;
use Quiote\Execution\ActionDescriptor;
use Quiote\Execution\ExecutionState;
use Psr\SimpleCache\CacheInterface;

/**
 * Unit test to verify that ActionViewCache works correctly for caching rendered actions/views.
 *
 * This test verifies:
 * 1. Cache storage and retrieval works
 * 2. Fingerprint-based cache keys allow per-user caching
 * 3. Cache invalidation works per module and per action
 * 4. Cached payload includes all necessary state for replay
 */
class ActionViewCachingTest extends TestCase
{
    private ActionViewCache $cache;

    protected function setUp(): void
    {
        parent::setUp();
        // Use an in-memory cache for testing (wrapped in Psr16Adapter for SimpleCache interface)
        $adapter = new \Symfony\Component\Cache\Adapter\ArrayAdapter();
        $psr16Cache = new \Symfony\Component\Cache\Psr16Cache($adapter);
        $this->cache = new ActionViewCache($psr16Cache, 300); // 5 minute TTL
    }

    public function testCacheStoreAndRetrieve(): void
    {
        $module = 'TestModule';
        $action = 'TestAction';
        $outputType = 'html';
        $payload = [
            'view_module' => 'TestModule',
            'view_name' => 'Success',
            'action_attributes' => ['foo' => 'bar'],
            'response_content' => '<html><body>Test Content</body></html>',
            'user_fingerprint' => null,
        ];

        // Store in cache
        $this->cache->set($module, $action, $outputType, $payload);

        // Retrieve from cache
        $retrieved = $this->cache->get($module, $action, $outputType);

        // Verify payload was stored and retrieved correctly
        $this->assertNotNull($retrieved);
        $this->assertEquals('Success', $retrieved['view_name']);
        $this->assertEquals('TestModule', $retrieved['view_module']);
        $this->assertEquals('<html><body>Test Content</body></html>', $retrieved['response_content']);
        $this->assertEquals(['foo' => 'bar'], $retrieved['action_attributes']);
    }

    public function testCacheWithFingerprint(): void
    {
        $module = 'TestModule';
        $action = 'TestAction';
        $outputType = 'html';

        $payloadForGuest = [
            'response_content' => 'Guest Content',
            'view_name' => 'GuestView',
            'user_fingerprint' => 'guest',
        ];

        $payloadForUser = [
            'response_content' => 'User Content',
            'view_name' => 'UserView',
            'user_fingerprint' => 'user_123',
        ];

        // Store separate cache entries for different fingerprints
        $this->cache->set($module, $action, $outputType, $payloadForGuest, null, 'guest');
        $this->cache->set($module, $action, $outputType, $payloadForUser, null, 'user_123');

        // Retrieve with fingerprints - should get user-specific content
        $retrievedForUser = $this->cache->get($module, $action, $outputType, 'user_123');
        $this->assertNotNull($retrievedForUser);
        $this->assertEquals('User Content', $retrievedForUser['response_content']);
        $this->assertEquals('UserView', $retrievedForUser['view_name']);

        // Retrieve for guest
        $retrievedForGuest = $this->cache->get($module, $action, $outputType, 'guest');
        $this->assertNotNull($retrievedForGuest);
        $this->assertEquals('Guest Content', $retrievedForGuest['response_content']);
        $this->assertEquals('GuestView', $retrievedForGuest['view_name']);
    }

    public function testCacheDelete(): void
    {
        $module = 'TestModule';
        $action = 'TestAction';
        $outputType = 'html';
        $payload = ['response_content' => 'Test'];

        // Store in cache
        $this->cache->set($module, $action, $outputType, $payload);
        $this->assertNotNull($this->cache->get($module, $action, $outputType));

        // Delete from cache
        $this->cache->delete($module, $action, $outputType);
        $this->assertNull($this->cache->get($module, $action, $outputType));
    }

    public function testCacheWithExecutionState(): void
    {
        $module = 'TestModule';
        $action = 'TestAction';
        $outputType = 'html';

        $payload = [
            'view_module' => 'TestModule',
            'view_name' => 'Success',
            'response_content' => '<html>Success</html>',
            'descriptor' => [
                'module' => 'TestModule',
                'action' => 'TestAction',
                'method' => 'GET',
                'outputType' => 'html',
                'isSimple' => true,
            ],
            'state' => [
                'validationDecision' => 'passed',
                'validationErrors' => [],
                'viewModule' => 'TestModule',
                'viewName' => 'Success',
                'securityDecision' => 'Allow',
            ],
            'action_attributes' => ['id' => '123'],
        ];

        // Store payload with execution state
        $this->cache->set($module, $action, $outputType, $payload);

        // Retrieve and verify state was preserved
        $retrieved = $this->cache->get($module, $action, $outputType);
        $this->assertNotNull($retrieved);
        $this->assertEquals('passed', $retrieved['state']['validationDecision']);
        $this->assertEquals('Allow', $retrieved['state']['securityDecision']);
        $this->assertEquals('TestModule', $retrieved['state']['viewModule']);
        $this->assertEquals('Success', $retrieved['state']['viewName']);
    }

    public function testCacheTtl(): void
    {
        $module = 'TestModule';
        $action = 'TestAction';
        $outputType = 'html';
        $payload = ['response_content' => 'Test Content'];

        // Store with short TTL (1 second)
        $this->cache->set($module, $action, $outputType, $payload, 1);

        // Should be retrievable immediately
        $retrieved = $this->cache->get($module, $action, $outputType);
        $this->assertNotNull($retrieved);

        // Wait for TTL to expire
        sleep(2);

        // Should be expired and unretrievable
        $expired = $this->cache->get($module, $action, $outputType);
        $this->assertNull($expired, 'Cache entry should have expired after TTL');
    }

    public function testCacheMultipleActions(): void
    {
        // Store different actions in cache
        $payload1 = ['response_content' => 'Action 1 Content'];
        $payload2 = ['response_content' => 'Action 2 Content'];

        $this->cache->set('Module', 'Action1', 'html', $payload1);
        $this->cache->set('Module', 'Action2', 'html', $payload2);

        // Retrieve separately
        $retrieved1 = $this->cache->get('Module', 'Action1', 'html');
        $retrieved2 = $this->cache->get('Module', 'Action2', 'html');

        $this->assertNotNull($retrieved1);
        $this->assertNotNull($retrieved2);
        $this->assertEquals('Action 1 Content', $retrieved1['response_content']);
        $this->assertEquals('Action 2 Content', $retrieved2['response_content']);
    }

    public function testKeyComposition(): void
    {
        // Verify that different modules/actions/outputTypes create different cache keys
        $payload = ['response_content' => 'Test'];

        // Store same content under different keys
        $this->cache->set('Module1', 'Action', 'html', $payload);
        $this->cache->set('Module2', 'Action', 'html', ['response_content' => 'Different Module']);
        $this->cache->set('Module1', 'Action', 'json', ['response_content' => 'Different OutputType']);

        // Verify they don't overwrite each other
        $m1_action_html = $this->cache->get('Module1', 'Action', 'html');
        $m2_action_html = $this->cache->get('Module2', 'Action', 'html');
        $m1_action_json = $this->cache->get('Module1', 'Action', 'json');

        $this->assertEquals('Test', $m1_action_html['response_content']);
        $this->assertEquals('Different Module', $m2_action_html['response_content']);
        $this->assertEquals('Different OutputType', $m1_action_json['response_content']);
    }
}
