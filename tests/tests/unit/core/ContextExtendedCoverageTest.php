<?php

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Quiote\Context;
use Nyholm\Psr7\ServerRequest;
use Quiote\Config\Config;
use Quiote\Exception\QuioteException;
use Quiote\Test\Routing\TestRouting;
// MockStorage loaded from test/lib classmap (global namespace)

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

/**
 * Additional focused coverage for Context worker-mode helpers & lazy recreation paths.
 */
#[RunTestsInSeparateProcesses]
class ContextExtendedCoverageTest extends TestCase
{
    private function ctx(): Context
    {
        // Explicitly use a default context name to avoid relying on core.default_context config.
        return Context::getInstance('default');
    }

    private function injectLogger(Context $ctx): void
    {
        // Logging now goes through the PSR-3 Log facade; there is no per-context
        // loggerManager to inject. Keep use_logging on for any gated paths.
        if (method_exists(Config::class, 'set')) {
            Config::set('core.use_logging', true);
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

    public function testHandleAdoptsInboundCorrelationIdHeader()
    {
        $ctx = $this->ctx();
        (new ReflectionObject($ctx))->getProperty('routing')->setValue($ctx, new TestRouting());

        $req = (new ServerRequest('GET', '/foo'))->withHeader('X-Correlation-Id', 'upstream-123');
        $res = $ctx->handle($req);

        $this->assertSame('upstream-123', $ctx->getCorrelationId(), 'inbound correlation id should be adopted');
        $this->assertSame('upstream-123', $res->getHeaderLine('X-Correlation-Id'), 'adopted id should be echoed back');
    }

    public function testHandleEchoesGeneratedCorrelationIdOnResponse()
    {
        $ctx = $this->ctx();
        (new ReflectionObject($ctx))->getProperty('routing')->setValue($ctx, new TestRouting());

        $res = $ctx->handle(new ServerRequest('GET', '/foo'));

        $this->assertNotSame('', $res->getHeaderLine('X-Correlation-Id'));
        $this->assertSame($ctx->getCorrelationId(), $res->getHeaderLine('X-Correlation-Id'));
    }

    public function testHandleCapsOverlongInboundCorrelationId()
    {
        $ctx = $this->ctx();
        (new ReflectionObject($ctx))->getProperty('routing')->setValue($ctx, new TestRouting());

        // A caller-supplied header becomes a log field and a response header, so
        // an absurdly long value is length-capped before adoption. (Control-byte
        // stripping is covered by CorrelationIdTest — Nyholm's PSR-7 refuses to
        // even construct a request with a CRLF header value, so that vector can't
        // reach handle() through a normal request in the first place.)
        $req = (new ServerRequest('GET', '/foo'))->withHeader('X-Correlation-Id', str_repeat('x', 500));
        $ctx->handle($req);

        $this->assertSame(200, mb_strlen($ctx->getCorrelationId()));
    }

    public function testHandleRespectsConfiguredHeaderNameAndExposeFlag()
    {
        Config::set('core.correlation_id.header', 'Request-Id', true);
        Config::set('core.correlation_id.expose', false, true);
        try {
            $ctx = $this->ctx();
            (new ReflectionObject($ctx))->getProperty('routing')->setValue($ctx, new TestRouting());

            $req = (new ServerRequest('GET', '/foo'))->withHeader('Request-Id', 'rid-9');
            $res = $ctx->handle($req);

            $this->assertSame('rid-9', $ctx->getCorrelationId());
            $this->assertFalse($res->hasHeader('Request-Id'), 'expose=false must suppress the response header');
        } finally {
            Config::remove('core.correlation_id.header');
            Config::remove('core.correlation_id.expose');
        }
    }

    public function testResetClearsLogContextScope()
    {
        $ctx = $this->ctx();
        $this->injectLogger($ctx);
        // Simulate a request that left ambient scope on the stack.
        \Quiote\Logging\LogContext::enrich(['rid' => 'req-A', 'userId' => 99]);
        $this->assertFalse(\Quiote\Logging\LogContext::isEmpty());
        $ctx->reset();
        $this->assertTrue(
            \Quiote\Logging\LogContext::isEmpty(),
            'reset() must clear ambient log scope so it cannot leak into the next worker request'
        );
    }

    public function testHandleEnrichesLogScopeWithCorrelationId()
    {
        $ctx = $this->ctx();
        $ro = new ReflectionObject($ctx);
        $ro->getProperty('routing')->setValue($ctx, new TestRouting());
        // Leftover scope from a prior request must not survive into this one.
        \Quiote\Logging\LogContext::enrich(['stale' => 'from-prior-request']);
        $ctx->handle(new ServerRequest('GET', '/foo'));
        $scope = \Quiote\Logging\LogContext::current();
        $this->assertArrayNotHasKey('stale', $scope, 'handle() must start a fresh scope');
        $this->assertSame($ctx->getCorrelationId(), $scope['rid'] ?? null, 'handle() must enrich scope with rid');
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
        $dummyClass = $dummy::class;
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
            // Synthesize factory info using default WebRequest implementation
            $rfiProp->setValue($ctx, [
                'class' => \Quiote\Request\WebRequest::class,
                'parameters' => []
            ]);
        }
        $req = $ctx->getRequest();
        $storage = $ctx->getStorage(); // now mock storage
        $this->assertInstanceOf(MockStorage::class, $storage);
        $user = $ctx->getUser();
        $dbm = null;
        if (Config::get('core.use_database', false)) {
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
            // reset() intentionally keeps the databaseManager alive (calls
            // recycleConnections() instead of nulling) to avoid costly
            // re-initialization in persistent worker mode.
            $this->assertNotNull($p->getValue($ctx), 'databaseManager should survive reset (recycleConnections strategy)');
            $this->assertSame($dbm, $p->getValue($ctx), 'Same databaseManager instance should persist across reset');
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
        $this->expectException(QuioteException::class);
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
        $seq = array_values(array_filter($seqProp->getValue($ctx), fn($c) => !($c instanceof \Quiote\User\User)));
        $seqProp->setValue($ctx, $seq);
        $user2 = $ctx->getUser();
        $this->assertInstanceOf($user1::class, $user2);
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
        // Since WebRequest extends ServerRequest, getCurrentPsrRequest() returns the request
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
        $this->assertInstanceOf(\Quiote\Execution\SlotDispatcher::class, $sd1);
    }

    public function testControllerRecreatedAfterResetAndShutdownSequenceOrderMaintained()
    {
        $ctx = $this->ctx();
        $this->injectLogger($ctx);
        $ro = new ReflectionObject($ctx);
        // Capture controller factory info if missing (synthesize minimal info)
        $cfiProp = $ro->getProperty('controllerFactoryInfo');

        if ($cfiProp->getValue($ctx) === null) {
            // Use base Controller implementation
            $cfiProp->setValue($ctx, [
                'class' => \Quiote\Controller\Controller::class,
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
            if ($comp instanceof \Quiote\User\User) {
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
        if (method_exists(Config::class, 'set')) {
            Config::set('core.use_translation', false);
        }
        $this->assertNull($ctx->getTranslationManager(), 'Translation manager should be null when translations disabled');
        // Enable translations and synthesize factory info to simulate enabled environment
        if (method_exists(Config::class, 'set')) {
            Config::set('core.use_translation', true);
        }
        // Enable logging-gated paths for this reset coverage test.
        $this->injectLogger($ctx);
        $tmProp = $ro->getProperty('translationManager');

        if ($tmProp->getValue($ctx) === null) {
            // Minimal instantiation of TranslationManager
            if (class_exists(\Quiote\Translation\TranslationManager::class)) {
                $tm = new \Quiote\Translation\TranslationManager();
                if (is_callable($tm->initialize(...))) {
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
        if (method_exists(Config::class, 'set')) {
            Config::set('core.use_database', true);
        }
        $this->injectLogger($ctx);
        $ro = new ReflectionObject($ctx);
        $dbmFi = $ro->getProperty('databaseManagerFactoryInfo');

        if ($dbmFi->getValue($ctx) === null) {
            $dbmFi->setValue($ctx, ['class' => \Quiote\Database\DatabaseManager::class, 'parameters' => []]);
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
        // Since PHP84 performance work: reset() now calls recycleConnections() instead of
        // nulling the manager, so the same instance should stay alive across requests.
        $dbm2 = $dbmProp->getValue($ctx);
        $this->assertNotNull($dbm2, 'Database manager should remain alive after reset (recycleConnections strategy)');
        $this->assertSame($dbm1, $dbm2, 'Same database manager instance should persist across reset — avoids re-initialization cost');
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
        $ctx->reset(); // kernel is kept alive; reset() no longer calls psrKernel->reset() (avoids pipeline rebuild)
        // Reinject mock storage after reset since reset nulls storage
        $storageProp->setValue($ctx, new MockStorage());
        $kernelAfter = $psrKernelProp->getValue($ctx);
        $this->assertSame($kernel, $kernelAfter, 'Kernel instance persists across reset');
        // Since PHP84 performance work: psrKernel->reset() is no longer called, so the
        // middleware stack stays built and the debug stack retains its previous entries.
        $this->assertNotEmpty($kernelAfter->debugStack(), 'Kernel debug stack persists across reset (no rebuild needed)');
        // Re-handle reuses the same already-built stack
        $ctx->handle(new ServerRequest('GET', '/kernel2'));
        $this->assertNotEmpty($kernelAfter->debugStack(), 'Kernel debug stack populated after second handle');
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
            if ($c instanceof \Quiote\User\User) {
                $userCount++;
            }
        }
        $this->assertLessThanOrEqual(2, $userCount, 'Shutdown sequence should not accumulate excessive user duplicates');
    }
}
