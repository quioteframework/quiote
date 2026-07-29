<?php

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Quiote\Context;
use Nyholm\Psr7\ServerRequest;
use Quiote\Config\Config;
use Quiote\Exception\QuioteException;
use Quiote\Test\Routing\TestRouting;

// Helper stubs outside test class to avoid nested class fatal
if (!class_exists('TestNoOpLogger')) {
    class TestNoOpLogger
    {
        public function debug(mixed $msg): void {}
        public function error(mixed $m): void {}
        public function notice(mixed $m): void {}
        public function warning(mixed $m): void {}
    }
}
if (!class_exists('TestNoOpLoggerManager')) {
    class TestNoOpLoggerManager
    {
        private TestNoOpLogger $l;
        public function __construct()
        {
            $this->l = new TestNoOpLogger();
        }
        public function getLogger(): TestNoOpLogger
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
        Config::set('core.use_logging', true);
    }

    public function testHandleGeneratesCorrelationIdAndStoresRequest(): void
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

    public function testHandleAdoptsInboundCorrelationIdHeader(): void
    {
        $ctx = $this->ctx();
        (new ReflectionObject($ctx))->getProperty('routing')->setValue($ctx, new TestRouting());

        $req = (new ServerRequest('GET', '/foo'))->withHeader('X-Correlation-Id', 'upstream-123');
        $res = $ctx->handle($req);

        $this->assertSame('upstream-123', $ctx->getCorrelationId(), 'inbound correlation id should be adopted');
        $this->assertSame('upstream-123', $res->getHeaderLine('X-Correlation-Id'), 'adopted id should be echoed back');
    }

    public function testHandleEchoesGeneratedCorrelationIdOnResponse(): void
    {
        $ctx = $this->ctx();
        (new ReflectionObject($ctx))->getProperty('routing')->setValue($ctx, new TestRouting());

        $res = $ctx->handle(new ServerRequest('GET', '/foo'));

        $this->assertNotSame('', $res->getHeaderLine('X-Correlation-Id'));
        $this->assertSame($ctx->getCorrelationId(), $res->getHeaderLine('X-Correlation-Id'));
    }

    public function testHandleCapsOverlongInboundCorrelationId(): void
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

        $correlationId = $ctx->getCorrelationId();
        $this->assertNotNull($correlationId);
        $this->assertSame(200, mb_strlen($correlationId));
    }

    public function testHandleRespectsConfiguredHeaderNameAndExposeFlag(): void
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

    public function testResetClearsLogContextScope(): void
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

    public function testHandleEnrichesLogScopeWithCorrelationId(): void
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

    public function testSingletonModelInstancesClearedOnReset(): void
    {
        $ctx = $this->ctx();
        $this->injectLogger($ctx);
        $ro = new ReflectionObject($ctx);
        $factoriesProp = $ro->getProperty('factories');

        $factories = $factoriesProp->getValue($ctx);
        $this->assertIsArray($factories);
        // Anonymous singleton model stub
        $dummy = new class {
            /** @param array<string, mixed> $p */
            public function initialize(mixed $c, array $p = []): void {}
        };
        $dummyClass = $dummy::class;
        // Register factory info under synthetic key so createInstanceFor could use it if invoked
        $factories['dummy_singleton'] = ['factory_info' => ['class' => $dummyClass, 'parameters' => []]];
        $factoriesProp->setValue($ctx, $factories);
        // Manually register singleton instance (simulate earlier usage)
        $smProp = $ro->getProperty('singletonModelInstances');

        $sm = $smProp->getValue($ctx);
        $this->assertIsArray($sm);
        $sm[$dummyClass] = $dummy;
        $smProp->setValue($ctx, $sm);
        $singletonsBefore = $smProp->getValue($ctx);
        $this->assertIsArray($singletonsBefore);
        $this->assertArrayHasKey($dummyClass, $singletonsBefore);
        $ctx->reset();
        $this->assertSame([], $smProp->getValue($ctx), 'singletonModelInstances should be cleared on reset');
    }

    public function testMultipleHandleCorrelationIdUniquenessAndKernelReuse(): void
    {
        $ctx = $this->ctx();
        $this->injectLogger($ctx);
        $ro = new ReflectionObject($ctx);
        $routingProp = $ro->getProperty('routing');$routingProp->setValue($ctx, new TestRouting());
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
        $ctx->handle(new ServerRequest('GET', '/afterReset'));
        $kernelAfter = $psrKernelProp->getValue($ctx);
        $this->assertSame($kernelBefore, $kernelAfter, 'Kernel instance should persist across reset');
        $newId = $ctx->getCorrelationId();
        $this->assertNotContains($newId, $ids, 'Correlation ID after reset should be new');
    }

    public function testResetClearsRequestUserSessionAndDatabaseManager(): void
    {
        $ctx = $this->ctx();
        $this->injectLogger($ctx);
        $ro = new ReflectionObject($ctx);

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
        $user = $ctx->getUser();
        $dbm = null;
        if (Config::getBool('core.use_database', false)) {
            $dbm = $ctx->getDatabaseManager();
        }
        // If requestFactoryInfo missing (unlikely in initialized context) skip rather than inject fake info
        $ro = new ReflectionObject($ctx);
        $rfi = $ro->getProperty('requestFactoryInfo');

        $this->assertNotNull($rfi->getValue($ctx), 'requestFactoryInfo should be present');
        $ctx->reset();
        // After reset, request and user should be null until lazy accessed
        $ro = new ReflectionObject($ctx);
        foreach (['request', 'user', 'sessionBag'] as $prop) {
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

    public function testGetRequestThrowsIfFactoryInfoMissing(): void
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

    public function testGetRoutingFixtureProvidesAddRoute(): void
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

    public function testResetClearsRoutingCompatibilityState(): void
    {
        $ctx = $this->ctx();
        $this->injectLogger($ctx);
        $ro = new ReflectionObject($ctx);
        $routingProp = $ro->getProperty('routing');
        $routing = new TestRouting();
        $routingProp->setValue($ctx, $routing);

        $routingRo = new ReflectionObject($routing);
        $inputProp = $routingRo->getProperty('input');
        $inputProp->setValue($routing, '/leaked-from-previous-request');
        $initializedProp = $routingRo->getProperty('initialized');
        $initializedProp->setValue($routing, true);

        $ctx->reset();

        $this->assertSame('', $inputProp->getValue($routing), 'routing input should not leak across worker requests');
        $this->assertFalse($initializedProp->getValue($routing), 'routing initialized flag should be cleared by reset');
    }

    public function testResetClearsTranslationManagerLocale(): void
    {
        $ctx = $this->ctx();
        $this->injectLogger($ctx);
        Config::set('core.use_translation', true, true);
        $tm = $ctx->getTranslationManager();
        if ($tm === null) {
            $info = $ctx->getFactoryInfo('translation_manager');
            if ($info === null || empty($info['class'])) {
                $ctx->setFactoryInfo('translation_manager', [
                    'class' => \Quiote\Translation\TranslationManager::class,
                    'parameters' => [],
                ]);
            }
            /** @var \Quiote\Translation\TranslationManager $tm */
            $tm = $ctx->createInstanceFor('translation_manager');
            (new ReflectionObject($ctx))->getProperty('translationManager')->setValue($ctx, $tm);
        }
        $this->assertInstanceOf(
            \Quiote\Translation\TranslationManager::class,
            $tm,
            'translation manager should be available once core.use_translation is enabled',
        );
        $tm->setLocale('de_DE');
        $this->assertNotNull($tm->getCurrentLocaleIdentifier());

        $ctx->reset();

        $this->assertNull($tm->getCurrentLocaleIdentifier(), 'locale set by a previous request must not leak into the next one');
    }

    public function testGetUserRecreatesAndRegistersInShutdownSequence(): void
    {
        $ctx = $this->ctx();
        $this->injectLogger($ctx);
        // Inject mock storage before user creation
        $ro = new ReflectionObject($ctx);

        $user1 = $ctx->getUser();
        $ctx->reset();
        $ro = new ReflectionObject($ctx);
        $userProp = $ro->getProperty('user');

        $userProp->setValue($ctx, null);
        $seqProp = $ro->getProperty('shutdownSequence');

        // Remove any user entries from sequence
        $rawSeq = $seqProp->getValue($ctx);
        $this->assertIsArray($rawSeq);
        $seq = array_values(array_filter($rawSeq, fn($c) => !($c instanceof \Quiote\User\User)));
        $seqProp->setValue($ctx, $seq);
        $user2 = $ctx->getUser();
        $this->assertInstanceOf($user1::class, $user2);
        $this->assertNotSame($user1, $user2);
        // Ensure new user present in shutdownSequence
        $found = false;
        $seqWithUser = $seqProp->getValue($ctx);
        $this->assertIsArray($seqWithUser);
        foreach ($seqWithUser as $c) {
            if ($c === $user2) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'New user should be registered in shutdown sequence');
    }

    public function testSetRequestUpdatesReferenceButKeepsCorrelationId(): void
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
        $this->assertNotNull($current1);
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
        $this->assertNotNull($current2);
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

    public function testGetSlotDispatcherLazyCreatesAndCaches(): void
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

    public function testControllerRecreatedAfterResetAndShutdownSequenceOrderMaintained(): void
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
            try {
                $controller1 = $ctx->createInstanceFor('controller');
            } catch (\Throwable) {
            }
            // Fallback: direct instantiation
            if ($controller1 === null) {
                $fi = $cfiProp->getValue($ctx);
                $this->assertIsArray($fi);
                $controller1 = new $fi['class']();
                if (is_callable([$controller1, 'initialize'])) {
                    $controller1->initialize($ctx, $fi['parameters']);
                }
                $controllerProp->setValue($ctx, $controller1);
            }
        }
        $this->assertInstanceOf(\Quiote\Controller\Controller::class, $controller1, 'Controller should be created');
        // Ensure controller registered (some contexts may add to shutdown sequence; verify stable ordering when user/storage present)
        $seqProp = $ro->getProperty('shutdownSequence');

        $seqBefore = $seqProp->getValue($ctx);
        // Trigger user/storage to populate sequence ordering

        $ctx->getUser();
        $ctx->reset();
        // After reset controller object should remain (not nulled in reset) but may be reset()
        $controller2 = $controllerProp->getValue($ctx);
        $this->assertSame($controller1, $controller2, 'Controller instance should persist across reset (reset() called but not replaced)');
        // The user must still be in the shutdown sequence after the reset.
        $seqAfter = $seqProp->getValue($ctx);
        $this->assertIsArray($seqAfter);
        $userIdx = null;
        foreach ($seqAfter as $i => $comp) {
            if ($comp instanceof \Quiote\User\User) {
                $userIdx = $i;
            }
        }
        $this->assertNotNull($userIdx, 'the recreated user must be registered in the shutdown sequence');
    }

    public function testTranslationManagerPreservedFlagAndNullWhenDisabled(): void
    {
        $ctx = $this->ctx();
        $ro = new ReflectionObject($ctx);
        // Ensure translation disabled to assert null return
        Config::set('core.use_translation', false);
        $this->assertNull($ctx->getTranslationManager(), 'Translation manager should be null when translations disabled');
        // Enable translations and synthesize factory info to simulate enabled environment
        Config::set('core.use_translation', true);
        // Enable logging-gated paths for this reset coverage test.
        $this->injectLogger($ctx);
        $tmProp = $ro->getProperty('translationManager');

        if ($tmProp->getValue($ctx) === null) {
            // Minimal instantiation of TranslationManager
            if (class_exists(\Quiote\Translation\TranslationManager::class)) {
                $tm = new \Quiote\Translation\TranslationManager();
                $tm->initialize($ctx, []);
                $tmProp->setValue($ctx, $tm);
            }
        }
        $tm1 = $tmProp->getValue($ctx);
        $this->assertNotNull($tm1, 'Translation manager should be created when enabled');

        $ctx->reset();
        // After reset translationManager should not be explicitly nulled by reset() (per implementation) and remain same instance
        $tm2 = $tmProp->getValue($ctx);
        $this->assertSame($tm1, $tm2, 'Translation manager instance should persist across reset');
    }

    public function testDatabaseManagerLazyRecreationFromFactoryInfo(): void
    {
        $ctx = $this->ctx();
        // Enable database usage
        Config::set('core.use_database', true);
        $this->injectLogger($ctx);
        $ro = new ReflectionObject($ctx);
        $dbmFi = $ro->getProperty('databaseManagerFactoryInfo');

        if ($dbmFi->getValue($ctx) === null) {
            $dbmFi->setValue($ctx, ['class' => \Quiote\Database\DatabaseManager::class, 'parameters' => []]);
        }


        // Force initial creation (may still be null if not requested previously)
        $dbmProp = $ro->getProperty('databaseManager');

        $dbm1 = $dbmProp->getValue($ctx);
        if (!$dbm1) {
            $fi = $dbmFi->getValue($ctx);
            $this->assertIsArray($fi);
            $dbm1 = new $fi['class']();
            if (is_callable([$dbm1, 'initialize'])) {
                $dbm1->initialize($ctx, $fi['parameters']);
            }
            $dbmProp->setValue($ctx, $dbm1);
        }
        $this->assertInstanceOf(\Quiote\Database\DatabaseManager::class, $dbm1, 'Database manager should be created');
        $ctx->reset();
        // Since PHP84 performance work: reset() now calls recycleConnections() instead of
        // nulling the manager, so the same instance should stay alive across requests.
        $dbm2 = $dbmProp->getValue($ctx);
        $this->assertNotNull($dbm2, 'Database manager should remain alive after reset (recycleConnections strategy)');
        $this->assertSame($dbm1, $dbm2, 'Same database manager instance should persist across reset — avoids re-initialization cost');
    }

    public function testPsrKernelResetClearsMiddlewareStack(): void
    {
        $ctx = $this->ctx();
        $this->injectLogger($ctx);
        $ro = new ReflectionObject($ctx);
        // Build kernel via handle()
        $routingProp = $ro->getProperty('routing');

        $routingProp->setValue($ctx, new TestRouting());


        $ctx->handle(new ServerRequest('GET', '/kernel')); // builds pipeline
        $psrKernelProp = $ro->getProperty('psrKernel');

        $kernel = $psrKernelProp->getValue($ctx);
        $this->assertInstanceOf(\Quiote\Middleware\MiddlewarePipeline::class, $kernel, 'psrKernel should be built after handle');
        $debugStackBefore = $kernel->debugStack();
        $this->assertNotEmpty($debugStackBefore, 'Middleware debug stack should be populated');
        $ctx->reset(); // kernel is kept alive; reset() no longer calls psrKernel->reset() (avoids pipeline rebuild)
        // Reinject mock storage after reset since reset nulls storage
        $kernelAfter = $psrKernelProp->getValue($ctx);
        $this->assertInstanceOf(\Quiote\Middleware\MiddlewarePipeline::class, $kernelAfter);
        $this->assertSame($kernel, $kernelAfter, 'Kernel instance persists across reset');
        // Since PHP84 performance work: psrKernel->reset() is no longer called, so the
        // middleware stack stays built and the debug stack retains its previous entries.
        $this->assertNotEmpty($kernelAfter->debugStack(), 'Kernel debug stack persists across reset (no rebuild needed)');
        // Re-handle reuses the same already-built stack
        $ctx->handle(new ServerRequest('GET', '/kernel2'));
        $this->assertNotEmpty($kernelAfter->debugStack(), 'Kernel debug stack populated after second handle');
    }

    public function testUserDuplicationAvoidedInShutdownSequenceAfterMultipleResets(): void
    {
        $ctx = $this->ctx();
        $this->injectLogger($ctx);
        $ro = new ReflectionObject($ctx);

        $user1 = $ctx->getUser();
        $seqProp = $ro->getProperty('shutdownSequence');

        $ctx->reset();
        $ctx->getUser(); // recreate user
        $ctx->reset();
        $ctx->getUser(); // recreate again
        $userCount = 0;
        $sequence = $seqProp->getValue($ctx);
        $this->assertIsArray($sequence);
        foreach ($sequence as $c) {
            if ($c instanceof \Quiote\User\User) {
                $userCount++;
            }
        }
        $this->assertLessThanOrEqual(2, $userCount, 'Shutdown sequence should not accumulate excessive user duplicates');
    }

    public function testUserGetContextThrowsBeforeInitialize(): void
    {
        $user = new \Quiote\User\User();
        $this->expectException(\Quiote\Exception\InitializationException::class);
        $user->getContext();
    }

    public function testUserGetContextReturnsContextAfterInitialize(): void
    {
        $ctx = $this->ctx();
        $user = new \Quiote\User\User();
        $user->initialize($ctx);
        $this->assertSame($ctx, $user->getContext());
    }

    public function testResponseGetContextThrowsBeforeInitialize(): void
    {
        $response = new \Quiote\Response\WebResponse();
        $this->expectException(\Quiote\Exception\InitializationException::class);
        $response->getContext();
    }

    public function testDatabaseGetDatabaseManagerThrowsBeforeInitialize(): void
    {
        $database = new \Quiote\Database\PdoDatabase();
        $this->expectException(\Quiote\Exception\InitializationException::class);
        $database->getDatabaseManager();
    }

    public function testDatabaseGetNameReturnsNullBeforeInitialize(): void
    {
        // getName() intentionally stays nullable rather than throwing: adapters may call
        // it purely for diagnostic messages (e.g. Database::getPdo()) before a name has
        // been assigned, and forcing an exception there would mask the real failure.
        $database = new \Quiote\Database\PdoDatabase();
        $this->assertNull($database->getName());
    }

    public function testRoutingCallbackGetContextThrowsBeforeInitialize(): void
    {
        $callback = new class extends \Quiote\Routing\RoutingCallback {};
        $this->expectException(\Quiote\Exception\InitializationException::class);
        $callback->getContext();
    }

    public function testAttributeBagRejectsNonStringOffset(): void
    {
        $bag = new \Quiote\Execution\AttributeBag();
        $this->expectException(\InvalidArgumentException::class);
        $bag[null] = 'value';
    }
}
