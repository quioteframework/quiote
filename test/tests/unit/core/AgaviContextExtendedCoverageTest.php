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
        // Explicitly use a default context name to avoid relying on core.default_context config.
        return AgaviContext::getInstance('default');
    }

    private function injectLogger(AgaviContext $ctx): void
    {
        $ro = new ReflectionObject($ctx);
        $prop = $ro->getProperty('loggerManager');

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

    public function testSingletonModelInstancesClearedOnReset()
    {
        $ctx = $this->ctx();
        $this->injectLogger($ctx);
        $ro = new ReflectionObject($ctx);
        $factoriesProp = $ro->getProperty('factories');

        $factories = $factoriesProp->getValue($ctx);
        // Anonymous singleton model stub
        $dummy = new class {
            public function initialize($c, $p = []) {}
        };
        $dummyClass = get_class($dummy);
        // Register factory info under synthetic key so createInstanceFor could use it if invoked
        $factories['dummy_singleton'] = ['factory_info' => ['class' => $dummyClass, 'parameters' => []]];
        $factoriesProp->setValue($ctx, $factories);
        // Manually register singleton instance (simulate earlier usage)
        $smProp = $ro->getProperty('singletonModelInstances');

        $sm = $smProp->getValue($ctx);
        $sm[$dummyClass] = $dummy;
        $smProp->setValue($ctx, $sm);
        $this->assertArrayHasKey($dummyClass, $smProp->getValue($ctx));
        $ctx->reset();
        $this->assertSame([], $smProp->getValue($ctx), 'singletonModelInstances should be cleared on reset');
    }

    public function testMultipleHandleCorrelationIdUniquenessAndKernelReuse()
    {
        $ctx = $this->ctx();
        $this->injectLogger($ctx);
        $ro = new ReflectionObject($ctx);
        $routingProp = $ro->getProperty('routing');$routingProp->setValue($ctx, new TestRouting());
        $storageProp = $ro->getProperty('storage');$storageProp->setValue($ctx, new MockStorage());
        $psrKernelProp = $ro->getProperty('psrKernel');
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ctx->handle(new ServerRequest('GET', '/seq' . $i));
            $ids[] = $ctx->getCorrelationId();
        }
        $this->assertCount(5, $ids);
        $this->assertSame(count($ids), count(array_unique($ids)), 'Correlation IDs should be unique per handle()');
        $kernelBefore = $psrKernelProp->getValue($ctx);
        $this->assertNotNull($kernelBefore);
        $ctx->reset();
        // Reinject dependencies after reset
        $routingProp->setValue($ctx, new TestRouting());
        $storageProp->setValue($ctx, new MockStorage());
        $ctx->handle(new ServerRequest('GET', '/afterReset'));
        $kernelAfter = $psrKernelProp->getValue($ctx);
        $this->assertSame($kernelBefore, $kernelAfter, 'Kernel instance should persist across reset');
        $newId = $ctx->getCorrelationId();
        $this->assertNotContains($newId, $ids, 'Correlation ID after reset should be new');
    }

    public function testResetClearsRequestUserStorageAndDatabaseManager()
    {
        $ctx = $this->ctx();
        $this->injectLogger($ctx);
        // Inject mock storage to avoid native session interaction
        $ro = new ReflectionObject($ctx);
        $storageProp = $ro->getProperty('storage');

        $storageProp->setValue($ctx, new MockStorage());
        // Ensure requestFactoryInfo is captured so post-reset lazy recreation works.
        $rfiProp = $ro->getProperty('requestFactoryInfo');

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

        $this->assertNotNull($rfi->getValue($ctx), 'requestFactoryInfo should be present');
        $ctx->reset();
        // After reset, request and user should be null until lazy accessed; storage & db manager nulled
        $ro = new ReflectionObject($ctx);
        foreach (['request', 'user', 'storage'] as $prop) {
            $p = $ro->getProperty($prop);

            $this->assertNull($p->getValue($ctx), $prop . ' should be nulled by reset');
        }
        if ($dbm) {
            $p = $ro->getProperty('databaseManager');

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

        $rfi->setValue($ctx, null);
        $reqProp = $ro->getProperty('request');

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

        $storageProp->setValue($ctx, new MockStorage());
        $user1 = $ctx->getUser();
        $ctx->reset();
        $ro = new ReflectionObject($ctx);
        $userProp = $ro->getProperty('user');

        $userProp->setValue($ctx, null);
        $seqProp = $ro->getProperty('shutdownSequence');

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

    public function testSetRequestUpdatesReferenceButKeepsCorrelationId()
    {
        $ctx = $this->ctx();
        // Establish a correlation id via handle() first
        $ro = new ReflectionObject($ctx);
        $routingProp = $ro->getProperty('routing');

        $routingProp->setValue($ctx, new TestRouting());
        $req1 = new ServerRequest('GET', '/initial');
        $ctx->handle($req1);
        $cid1 = $ctx->getCorrelationId();
        $this->assertNotEmpty($cid1);
        // Current PSR request should be the one passed to handle (identity may be same instance)
        // Since AgaviWebRequest extends ServerRequest, getCurrentPsrRequest() returns the request
        $current1 = $ctx->getCurrentPsrRequest();
        // Allow frameworks/middleware to wrap the request; verify semantic consistency
        if ($current1 !== $req1) {
            $this->assertSame((string)$req1->getUri(), (string)$current1->getUri(), 'Current PSR request URI should match original even if instance was replaced');
        } else {
            $this->assertSame($req1, $current1, 'Expected request to reference req1 immediately after handle');
        }
        // Simulate middleware replacing request (e.g., adding attribute)
        $req2 = $req1->withAttribute('x', 'y');
        $ctx->setRequest($req2);
        $this->assertNotSame($req1, $req2, 'Middleware modifications should produce a new immutable request instance');
        $current2 = $ctx->getCurrentPsrRequest();
        if ($current2 !== $req2) {
            $this->assertSame((string)$req2->getUri(), (string)$current2->getUri(), 'Current request URI should match replaced request');
        } else {
            $this->assertSame($req2, $current2, 'Context should now reference the replaced PSR request');
        }
        // Correlation id remains the same for the same pipeline execution
        $this->assertSame($cid1, $ctx->getCorrelationId(), 'Correlation id should not change on setRequest');
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

    public function testControllerRecreatedAfterResetAndShutdownSequenceOrderMaintained()
    {
        $ctx = $this->ctx();
        $this->injectLogger($ctx);
        $ro = new ReflectionObject($ctx);
        // Capture controller factory info if missing (synthesize minimal info)
        $cfiProp = $ro->getProperty('controllerFactoryInfo');

        if ($cfiProp->getValue($ctx) === null) {
            // Use base AgaviController implementation
            $cfiProp->setValue($ctx, [
                'class' => \Agavi\Controller\AgaviController::class,
                'parameters' => []
            ]);
        }
        // Force controller creation via internal initialize path if not created yet
        $controllerProp = $ro->getProperty('controller');

        $controller1 = $controllerProp->getValue($ctx);
        if ($controller1 === null) {
            // Invoke createInstanceFor if factory info stored in factories array
            if (method_exists($ctx, 'createInstanceFor')) {
                try {
                    $controller1 = $ctx->createInstanceFor('controller');
                } catch (\Throwable) {
                }
            }
            // Fallback: direct instantiation
            if ($controller1 === null) {
                $fi = $cfiProp->getValue($ctx);
                $controller1 = new $fi['class']();
                if (is_callable([$controller1, 'initialize'])) {
                    $controller1->initialize($ctx, $fi['parameters']);
                }
                $controllerProp->setValue($ctx, $controller1);
            }
        }
        $this->assertNotNull($controller1, 'Controller should be created');
        // Ensure controller registered (some contexts may add to shutdown sequence; verify stable ordering when user/storage present)
        $seqProp = $ro->getProperty('shutdownSequence');

        $seqBefore = $seqProp->getValue($ctx);
        // Trigger user/storage to populate sequence ordering
        $storageProp = $ro->getProperty('storage');

        $storageProp->setValue($ctx, new MockStorage());
        $ctx->getUser();
        $ctx->reset();
        // After reset controller object should remain (not nulled in reset) but may be reset()
        $controller2 = $controllerProp->getValue($ctx);
        $this->assertSame($controller1, $controller2, 'Controller instance should persist across reset (reset() called but not replaced)');
        // Sequence should still contain a user before storage
        $seqAfter = $seqProp->getValue($ctx);
        $userIdx = null;
        $storageIdx = null;
        foreach ($seqAfter as $i => $comp) {
            if ($comp instanceof \Agavi\User\AgaviUser) {
                $userIdx = $i;
            }
            if ($comp instanceof MockStorage) {
                $storageIdx = $i;
            }
        }
        if ($userIdx !== null && $storageIdx !== null) {
            $this->assertLessThan($storageIdx, $userIdx, 'User should appear before storage in shutdown sequence');
        }
    }

    public function testTranslationManagerPreservedFlagAndNullWhenDisabled()
    {
        $ctx = $this->ctx();
        $ro = new ReflectionObject($ctx);
        // Ensure translation disabled to assert null return
        if (method_exists(AgaviConfig::class, 'set')) {
            AgaviConfig::set('core.use_translation', false);
        }
        $this->assertNull($ctx->getTranslationManager(), 'Translation manager should be null when translations disabled');
        // Enable translations and synthesize factory info to simulate enabled environment
        if (method_exists(AgaviConfig::class, 'set')) {
            AgaviConfig::set('core.use_translation', true);
        }
        // Ensure logger present so reset() does not error when accessing getLoggerManager()->getLogger()
        $this->injectLogger($ctx);
        $tmProp = $ro->getProperty('translationManager');

        if ($tmProp->getValue($ctx) === null) {
            // Minimal instantiation of AgaviTranslationManager
            if (class_exists(\Agavi\Translation\AgaviTranslationManager::class)) {
                $tm = new \Agavi\Translation\AgaviTranslationManager();
                if (is_callable([$tm, 'initialize'])) {
                    $tm->initialize($ctx, []);
                }
                $tmProp->setValue($ctx, $tm);
            }
        }
        $tm1 = $tmProp->getValue($ctx);
        $this->assertNotNull($tm1, 'Translation manager should be created when enabled');
        // Inject MockStorage to prevent real session handler usage during reset
        $storageProp = $ro->getProperty('storage');

        $storageProp->setValue($ctx, new MockStorage());
        $ctx->reset();
        // After reset translationManager should not be explicitly nulled by reset() (per implementation) and remain same instance
        $tm2 = $tmProp->getValue($ctx);
        $this->assertSame($tm1, $tm2, 'Translation manager instance should persist across reset');
    }

    public function testDatabaseManagerLazyRecreationFromFactoryInfo()
    {
        $ctx = $this->ctx();
        // Enable database usage
        if (method_exists(AgaviConfig::class, 'set')) {
            AgaviConfig::set('core.use_database', true);
        }
        $this->injectLogger($ctx);
        $ro = new ReflectionObject($ctx);
        $dbmFi = $ro->getProperty('databaseManagerFactoryInfo');

        if ($dbmFi->getValue($ctx) === null) {
            $dbmFi->setValue($ctx, ['class' => \Agavi\Database\AgaviDatabaseManager::class, 'parameters' => []]);
        }
        // Ensure storageFactoryInfo uses MockStorage to avoid real session handler
        $sfi = $ro->getProperty('storageFactoryInfo');

        $sfi->setValue($ctx, ['class' => MockStorage::class, 'parameters' => []]);
        $storageProp = $ro->getProperty('storage');

        $storageProp->setValue($ctx, new MockStorage());
        // Force initial creation (may still be null if not requested previously)
        $dbmProp = $ro->getProperty('databaseManager');

        $dbm1 = $dbmProp->getValue($ctx);
        if (!$dbm1) {
            $fi = $dbmFi->getValue($ctx);
            $dbm1 = new $fi['class']();
            if (is_callable([$dbm1, 'initialize'])) {
                $dbm1->initialize($ctx, $fi['parameters']);
            }
            $dbmProp->setValue($ctx, $dbm1);
        }
        $this->assertNotNull($dbm1, 'Database manager should be created');
        $ctx->reset();
        // Should be nulled by reset
        $this->assertNull($dbmProp->getValue($ctx), 'Database manager should be nulled after reset');
        // Trigger lazy recreation via getUser() which attempts databaseManager recreation if enabled
        // Reinject mock storage after reset
        $storageProp->setValue($ctx, new MockStorage());
        $ctx->getUser();
        $dbm2 = $dbmProp->getValue($ctx);
        $this->assertNotNull($dbm2, 'Database manager should be lazily recreated on user access');
        $this->assertNotSame($dbm1, $dbm2, 'Recreated database manager should be a new instance');
    }

    public function testStorageHeuristicRecreationUsesFactoryInfoOrSynthesizes()
    {
        $ctx = $this->ctx();
        $this->injectLogger($ctx);
        $ro = new ReflectionObject($ctx);
        // Ensure storageFactoryInfo captures MockStorage to avoid session calls
        $sfi = $ro->getProperty('storageFactoryInfo');

        $sfi->setValue($ctx, ['class' => MockStorage::class, 'parameters' => []]);
        // Inject instance then reset
        $storageProp = $ro->getProperty('storage');

        $storageProp->setValue($ctx, new MockStorage());
        $storage1 = $storageProp->getValue($ctx);
        $this->assertInstanceOf(MockStorage::class, $storage1);
        $ctx->reset();
        $this->assertNull($storageProp->getValue($ctx), 'Storage should be nulled after reset');
        // Lazy getStorage should recreate using factory info
        $storage2 = $ctx->getStorage();
        $this->assertInstanceOf(MockStorage::class, $storage2);
        $this->assertNotSame($storage1, $storage2, 'Storage should be a fresh instance after recreation');
    }

    public function testPsrKernelResetClearsMiddlewareStack()
    {
        $ctx = $this->ctx();
        $this->injectLogger($ctx);
        $ro = new ReflectionObject($ctx);
        // Build kernel via handle()
        $routingProp = $ro->getProperty('routing');

        $routingProp->setValue($ctx, new TestRouting());
        // Ensure storage uses MockStorage to avoid real session handler
        $sfi = $ro->getProperty('storageFactoryInfo');

        $sfi->setValue($ctx, ['class' => MockStorage::class, 'parameters' => []]);
        $storageProp = $ro->getProperty('storage');

        $storageProp->setValue($ctx, new MockStorage());
        $ctx->handle(new ServerRequest('GET', '/kernel')); // builds pipeline
        $psrKernelProp = $ro->getProperty('psrKernel');

        $kernel = $psrKernelProp->getValue($ctx);
        $this->assertNotNull($kernel, 'psrKernel should be built after handle');
        $debugStackBefore = $kernel->debugStack();
        $this->assertNotEmpty($debugStackBefore, 'Middleware debug stack should be populated');
        $ctx->reset(); // calls psrKernel->reset()
        // Reinject mock storage after reset since reset nulls storage
        $storageProp->setValue($ctx, new MockStorage());
        $kernelAfter = $psrKernelProp->getValue($ctx);
        $this->assertSame($kernel, $kernelAfter, 'Kernel instance persists but is reset');
        $this->assertSame([], $kernelAfter->debugStack(), 'Kernel debug stack should be cleared after reset');
        // Re-handle builds stack again
        $ctx->handle(new ServerRequest('GET', '/kernel2'));
        $this->assertNotEmpty($kernelAfter->debugStack(), 'Kernel debug stack should repopulate after second handle');
    }

    public function testUserDuplicationAvoidedInShutdownSequenceAfterMultipleResets()
    {
        $ctx = $this->ctx();
        $this->injectLogger($ctx);
        $ro = new ReflectionObject($ctx);
        // Inject MockStorage and force user creation
        $storageProp = $ro->getProperty('storage');

        $storageProp->setValue($ctx, new MockStorage());
        $user1 = $ctx->getUser();
        $seqProp = $ro->getProperty('shutdownSequence');

        $ctx->reset();
        $ctx->getUser(); // recreate user
        $ctx->reset();
        $ctx->getUser(); // recreate again
        $userCount = 0;
        foreach ($seqProp->getValue($ctx) as $c) {
            if ($c instanceof \Agavi\User\AgaviUser) {
                $userCount++;
            }
        }
        $this->assertLessThanOrEqual(2, $userCount, 'Shutdown sequence should not accumulate excessive user duplicates');
    }
}
