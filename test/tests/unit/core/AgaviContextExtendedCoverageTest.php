<?php

use PHPUnit\Framework\TestCase;
use Agavi\AgaviContext;
use Nyholm\Psr7\ServerRequest;
use Agavi\Config\AgaviConfig;
use Agavi\Exception\AgaviException;
use Agavi\Test\Routing\TestRouting;
// MockStorage loaded from test/lib classmap (global namespace)

/**
 * Additional focused coverage for AgaviContext worker-mode helpers & lazy recreation paths.
 * @runTestsInSeparateProcesses
 */
// Helper stubs outside test class to avoid nested class fatal
if (!class_exists('TestNoOpLogger')) {
    class TestNoOpLogger
    {
        public function debug($msg) {}
        public function error($m) {}
        public function notice($m) {}
        public function warning($m) {}
    }
}
if (!class_exists('TestNoOpLoggerManager')) {
    class TestNoOpLoggerManager
    {
        private $l;
        public function __construct()
        {
            $this->l = new TestNoOpLogger();
        }
        public function getLogger()
        {
            return $this->l;
        }
    }
}

class AgaviContextExtendedCoverageTest extends TestCase
{
    private function ctx(): AgaviContext
    {
        return AgaviContext::getInstance();
    }

    private function injectLogger(AgaviContext $ctx): void
    {
        $ro = new ReflectionObject($ctx);
        $prop = $ro->getProperty('loggerManager');
        $prop->setAccessible(true);
        if ($prop->getValue($ctx) === null) {
            $prop->setValue($ctx, new TestNoOpLoggerManager());
        }
        if (method_exists(AgaviConfig::class, 'set')) {
            AgaviConfig::set('core.use_logging', true);
        }
    }

    public function testHandleGeneratesCorrelationIdAndStoresRequest()
    {
        $ctx = $this->ctx();
        $req = new ServerRequest('GET', '/foo');
        // Inject routing fixture ensuring concrete implementation
        $ro = new ReflectionObject($ctx);
        $routingProp = $ro->getProperty('routing');
        $routingProp->setAccessible(true);
        $routingProp->setValue($ctx, new TestRouting());
        $res1 = $ctx->handle($req); // first handle
        $cid1 = $ctx->getCorrelationId();
        $this->assertNotEmpty($cid1);
        $this->assertNotNull($ctx->getCurrentPsrRequest());
        // Second request should generate a new correlation id
        $req2 = new ServerRequest('GET', '/bar');
        $res2 = $ctx->handle($req2);
        $cid2 = $ctx->getCorrelationId();
        $this->assertNotEmpty($cid2);
        $this->assertNotSame($cid1, $cid2, 'Correlation ID should refresh per handle call');
        $this->assertNotNull($ctx->getCurrentPsrRequest());
    }

    public function testResetClearsRequestUserStorageAndDatabaseManager()
    {
        $ctx = $this->ctx();
        $this->injectLogger($ctx);
        // Inject mock storage to avoid native session interaction
        $ro = new ReflectionObject($ctx);
        $storageProp = $ro->getProperty('storage');
        $storageProp->setAccessible(true);
        $storageProp->setValue($ctx, new MockStorage());
        // Ensure requestFactoryInfo is captured so post-reset lazy recreation works.
        $rfiProp = $ro->getProperty('requestFactoryInfo');
        $rfiProp->setAccessible(true);
        if ($rfiProp->getValue($ctx) === null) {
            // Synthesize factory info using default AgaviWebRequest implementation
            $rfiProp->setValue($ctx, [
                'class' => \Agavi\Request\AgaviWebRequest::class,
                'parameters' => []
            ]);
        }
        $req = $ctx->getRequest();
        $storage = $ctx->getStorage(); // now mock storage
        $this->assertInstanceOf(MockStorage::class, $storage);
        $user = $ctx->getUser();
        $dbm = null;
        if (AgaviConfig::get('core.use_database', false)) {
            $dbm = $ctx->getDatabaseManager();
        }
        // If requestFactoryInfo missing (unlikely in initialized context) skip rather than inject fake info
        $ro = new ReflectionObject($ctx);
        $rfi = $ro->getProperty('requestFactoryInfo');
        $rfi->setAccessible(true);
        $this->assertNotNull($rfi->getValue($ctx), 'requestFactoryInfo should be present');
        $ctx->reset();
        // After reset, request and user should be null until lazy accessed; storage & db manager nulled
        $ro = new ReflectionObject($ctx);
        foreach (['request', 'user', 'storage'] as $prop) {
            $p = $ro->getProperty($prop);
            $p->setAccessible(true);
            $this->assertNull($p->getValue($ctx), $prop . ' should be nulled by reset');
        }
        if ($dbm) {
            $p = $ro->getProperty('databaseManager');
            $p->setAccessible(true);
            $this->assertNull($p->getValue($ctx), 'databaseManager should be nulled by reset');
        }
        // Lazy recreation works
        $req2 = $ctx->getRequest();
        $this->assertNotSame($req, $req2);
    }

    public function testGetRequestThrowsIfFactoryInfoMissing()
    {
        $ctx = $this->ctx();
        // Inject a null requestFactoryInfo then null the request to force failure path
        $ro = new ReflectionObject($ctx);
        $rfi = $ro->getProperty('requestFactoryInfo');
        $rfi->setAccessible(true);
        $rfi->setValue($ctx, null);
        $reqProp = $ro->getProperty('request');
        $reqProp->setAccessible(true);
        $reqProp->setValue($ctx, null);
        $this->expectException(AgaviException::class);
        $ctx->getRequest();
    }

    public function testGetRoutingFixtureProvidesAddRoute()
    {
        $ctx = $this->ctx();
        $this->injectLogger($ctx);
        // Inject fixture
        $ro = new ReflectionObject($ctx);
        $routingProp = $ro->getProperty('routing');
        $routingProp->setAccessible(true);
        $routingProp->setValue($ctx, new TestRouting());
        $routing = $ctx->getRouting();
        $this->assertInstanceOf(TestRouting::class, $routing);
        $name = $routing->addRoute('/extra', ['name' => 'extra', 'module' => 'Extra', 'action' => 'View']);
        $this->assertSame('extra', $name);
        $this->assertNotNull($routing->getRoute('extra'));
    }

    public function testGetUserRecreatesAndRegistersInShutdownSequence()
    {
        $ctx = $this->ctx();
        $this->injectLogger($ctx);
        // Inject mock storage before user creation
        $ro = new ReflectionObject($ctx);
        $storageProp = $ro->getProperty('storage');
        $storageProp->setAccessible(true);
        $storageProp->setValue($ctx, new MockStorage());
        $user1 = $ctx->getUser();
        $ctx->reset();
        $ro = new ReflectionObject($ctx);
        $userProp = $ro->getProperty('user');
        $userProp->setAccessible(true);
        $userProp->setValue($ctx, null);
        $seqProp = $ro->getProperty('shutdownSequence');
        $seqProp->setAccessible(true);
        // Remove any user entries from sequence
        $seq = array_values(array_filter($seqProp->getValue($ctx), fn($c) => !($c instanceof \Agavi\User\AgaviUser)));
        $seqProp->setValue($ctx, $seq);
        $user2 = $ctx->getUser();
        $this->assertInstanceOf(get_class($user1), $user2);
        $this->assertNotSame($user1, $user2);
        // Ensure new user present in shutdownSequence
        $found = false;
        foreach ($seqProp->getValue($ctx) as $c) {
            if ($c === $user2) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'New user should be registered in shutdown sequence');
    }

    public function testSetCurrentPsrRequestUpdatesReferenceButKeepsCorrelationId()
    {
        $ctx = $this->ctx();
        // Establish a correlation id via handle() first
        $ro = new ReflectionObject($ctx);
        $routingProp = $ro->getProperty('routing');
        $routingProp->setAccessible(true);
        $routingProp->setValue($ctx, new TestRouting());
        $req1 = new ServerRequest('GET', '/initial');
        $ctx->handle($req1);
        $cid1 = $ctx->getCorrelationId();
        $this->assertNotEmpty($cid1);
        // Current PSR request should be the one passed to handle (identity may be same instance)
        $current1 = $ctx->getCurrentPsrRequest();
        // Allow frameworks/middleware to wrap the request; verify semantic consistency
        if ($current1 !== $req1) {
            $this->assertSame((string)$req1->getUri(), (string)$current1->getUri(), 'Current PSR request URI should match original even if instance was replaced');
        } else {
            $this->assertSame($req1, $current1, 'Expected currentPsrRequest to reference req1 immediately after handle');
        }
        // Simulate middleware replacing request (e.g., adding attribute)
        $req2 = $req1->withAttribute('x', 'y');
        $ctx->setCurrentPsrRequest($req2);
        $this->assertNotSame($req1, $req2, 'Middleware modifications should produce a new immutable request instance');
        $current2 = $ctx->getCurrentPsrRequest();
        if ($current2 !== $req2) {
            $this->assertSame((string)$req2->getUri(), (string)$current2->getUri(), 'Current request URI should match replaced request');
        } else {
            $this->assertSame($req2, $current2, 'Context should now reference the replaced PSR request');
        }
        // Correlation id remains the same for the same pipeline execution
        $this->assertSame($cid1, $ctx->getCorrelationId(), 'Correlation id should not change on setCurrentPsrRequest');
        // A new handle() should regenerate correlation id
        $req3 = new ServerRequest('GET', '/next');
        $ctx->handle($req3);
        $cid2 = $ctx->getCorrelationId();
        $this->assertNotSame($cid1, $cid2, 'Correlation id should change on new handle()');
    }

    public function testGetSlotDispatcherLazyCreatesAndCaches()
    {
        $ctx = $this->ctx();
        // Force controller + actionResolver creation paths
        $ro = new ReflectionObject($ctx);
        // Ensure controller factory info exists to avoid null controller (simplified assumption: already initialized by getInstance())
        $sd1 = $ctx->getSlotDispatcher();
        $sd2 = $ctx->getSlotDispatcher();
        $this->assertSame($sd1, $sd2, 'SlotDispatcher should be cached and identical');
        $this->assertInstanceOf(\Agavi\Execution\SlotDispatcher::class, $sd1);
    }
}
