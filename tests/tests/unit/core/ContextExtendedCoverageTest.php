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

    /**
     * The class/parameters the compiled factories configuration declares for a role -- what the
     * lazy worker-mode rebuild reads. Replaces reaching for the per-component *FactoryInfo
     * properties, which no longer exist now that the compiled file is a declaration.
     *
     * @return array{class: string, parameters: array<string, mixed>}
     */
    private function declarationFor(Context $ctx, string $role): array
    {
        $definitions = (new ReflectionObject($ctx))->getProperty('factoryDefinitions')->getValue($ctx);
        $this->assertInstanceOf(\Quiote\Config\Factory\FactoryDefinitions::class, $definitions);
        $info = $definitions->buildInfo($role);
        $this->assertIsArray($info, "the factories configuration should declare $role");

        return $info;
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
        // Second request should generate a new correlation id
        $req2 = new ServerRequest('GET', '/bar');
        $res2 = $ctx->handle($req2);
        $cid2 = $ctx->getCorrelationId();
        $this->assertNotEmpty($cid2);
        $this->assertNotSame($cid1, $cid2, 'Correlation ID should refresh per handle call');
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

    /**
     * The singleton-model cache lives in the ModelLocator now, so reset() has to reach it there.
     * A cache that survived the worker request boundary would serve request N's model, holding
     * request N's data, to request N+1.
     */
    public function testSingletonModelInstancesClearedOnReset(): void
    {
        $ctx = $this->ctx();
        $this->injectLogger($ctx);

        $before = $ctx->getModelLocator()->get(\Sandbox\Models\ContextTestSingletonModel::class);
        $this->assertSame(
            $before,
            $ctx->getModelLocator()->get(\Sandbox\Models\ContextTestSingletonModel::class),
            'a singleton model is shared within the request',
        );

        $ctx->reset();

        $this->assertNotSame(
            $before,
            $ctx->getModelLocator()->get(\Sandbox\Models\ContextTestSingletonModel::class),
            'the singleton model cache should be cleared on reset',
        );
    }

    public function testMultipleHandleCorrelationIdUniquenessAndKernelReuse(): void
    {
        $ctx = $this->ctx();
        $this->injectLogger($ctx);
        $ro = new ReflectionObject($ctx);
        $routingProp = $ro->getProperty('routing');$routingProp->setValue($ctx, new TestRouting());
        $handler = $ctx->getRequestHandler();
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ctx->handle(new ServerRequest('GET', '/seq' . $i));
            $ids[] = $ctx->getCorrelationId();
        }
        $this->assertCount(5, $ids);
        $this->assertSame(count($ids), count(array_unique($ids)), 'Correlation IDs should be unique per handle()');
        $this->assertTrue($handler->hasPipeline(), 'the pipeline is built by the first handle()');
        $kernelBefore = $handler->pipeline();
        $ctx->reset();
        // Reinject dependencies after reset
        $routingProp->setValue($ctx, new TestRouting());
        $ctx->handle(new ServerRequest('GET', '/afterReset'));
        $this->assertSame($kernelBefore, $handler->pipeline(), 'Kernel instance should persist across reset');
        $newId = $ctx->getCorrelationId();
        $this->assertNotContains($newId, $ids, 'Correlation ID after reset should be new');
    }

    public function testResetClearsRequestUserSessionAndDatabaseManager(): void
    {
        $ctx = $this->ctx();
        $this->injectLogger($ctx);
        $ro = new ReflectionObject($ctx);

        $req = $ctx->getRequest();
        $user = $ctx->getUser();
        $dbm = null;
        if (Config::getBool('core.use_database', false)) {
            $dbm = $ctx->getContainer()->tryGet(\Quiote\Database\DatabaseManager::class);
        }
        // The declaration the post-reset lazy recreation rebuilds from must be present.
        $definitions = (new ReflectionObject($ctx))->getProperty('factoryDefinitions')->getValue($ctx);
        $this->assertInstanceOf(\Quiote\Config\Factory\FactoryDefinitions::class, $definitions);
        $this->assertNotNull($definitions->buildInfo('request'), 'the request declaration should be present');
        $ctx->reset();
        // After reset, request and user should be null until lazy accessed
        $ro = new ReflectionObject($ctx);
        foreach (['request', 'user'] as $prop) {
            $p = $ro->getProperty($prop);

            $this->assertNull($p->getValue($ctx), $prop . ' should be nulled by reset');
        }

        // The session bag lives in the container now rather than in a property, so what reset() has to
        // achieve is that the next read answers a fresh default rather than the previous request's bag.
        $this->assertInstanceOf(
            \Quiote\Session\NullSessionBag::class,
            $ctx->getContainer()->get(\Quiote\Session\SessionBagInterface::class),
            'the session bag should not survive the request boundary',
        );
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

    public function testGetRequestThrowsWhenThereIsNoFactoryDeclaration(): void
    {
        $ctx = $this->ctx();
        // Drop the declarations, then the request, to force the failure path.
        $ro = new ReflectionObject($ctx);
        $ro->getProperty('factoryDefinitions')->setValue($ctx, null);
        $ro->getProperty('request')->setValue($ctx, null);

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

    public function testResetRestoresTheDefaultLocaleOnTheTranslationManager(): void
    {
        $ctx = $this->ctx();
        $this->injectLogger($ctx);
        $previousUseTranslation = Config::get('core.use_translation');
        Config::set('core.use_translation', true, true);
        $tm = $ctx->getContainer()->tryGet(\Quiote\Translation\TranslationManager::class);
        if ($tm === null) {
            // An on-demand slot is a transient container binding now, so arranging one is a binding
            // rather than a factory-info write.
            $container = $ctx->getContainer();
            if (!$container->has(\Quiote\Translation\TranslationManager::class)) {
                $container->setFactory(
                    \Quiote\Translation\TranslationManager::class,
                    function () use ($ctx): \Quiote\Translation\TranslationManager {
                        $manager = new \Quiote\Translation\TranslationManager();
                        $manager->initialize($ctx, []);

                        return $manager;
                    },
                    \Quiote\DI\Container::SCOPE_TRANSIENT,
                );
            }
            $tm = $container->get(\Quiote\Translation\TranslationManager::class);
            (new ReflectionObject($ctx))->getProperty('translationManager')->setValue($ctx, $tm);
        }
        $this->assertInstanceOf(
            \Quiote\Translation\TranslationManager::class,
            $tm,
            'translation manager should be available once core.use_translation is enabled',
        );
        $default = $tm->getDefaultLocaleIdentifier();
        $this->assertNotNull($default, 'the sandbox app configures a default locale');
        $tm->setLocale('de_DE');
        $this->assertSame('de_DE', $tm->getCurrentLocaleIdentifier());

        try {
            $ctx->reset();

            // Both halves of the contract: the locale this "request" selected is
            // gone, and the manager is left on its configured default rather than
            // on no locale at all -- the same instance serves the next request
            // without a second initialize(), and an empty identifier would make
            // its first template lookup throw.
            $this->assertNotSame('de_DE', $tm->getCurrentLocaleIdentifier(), 'locale set by a previous request must not leak into the next one');
            $this->assertSame($default, $tm->getCurrentLocaleIdentifier(), 'reset must leave the manager usable for the next request');
        } finally {
            Config::set('core.use_translation', $previousUseTranslation, true);
        }
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
        $sequence = $ctx->getShutdownSequence();

        // Remove any user entries from the sequence, so the recreation has to splice its own in.
        $sequence->remove(static fn(object $c): bool => $c instanceof \Quiote\User\User);

        $user2 = $ctx->getUser();
        $this->assertInstanceOf($user1::class, $user2);
        $this->assertNotSame($user1, $user2);
        $this->assertTrue(
            $sequence->has($user2),
            'New user should be registered in shutdown sequence',
        );
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
        // The request the context answers is the one handle() was given, wrapped as a WebRequest --
        // middleware is free to replace the instance, so the URI is what has to survive.
        $current1 = $ctx->getRequest();
        $this->assertSame((string)$req1->getUri(), (string)$current1->getUri(), 'the handled request URI should be what the context answers');
        // Simulate middleware replacing request (e.g., adding attribute)
        $req2 = $req1->withAttribute('x', 'y');
        $ctx->setRequest($req2);
        $this->assertNotSame($req1, $req2, 'Middleware modifications should produce a new immutable request instance');
        // Not an identity assertion: setRequest() may wrap a plain PSR request as a WebRequest, so
        // what has to hold is that the republished request's own state is what the context answers.
        $current2 = $ctx->getRequest();
        $this->assertSame((string)$req2->getUri(), (string)$current2->getUri());
        $this->assertSame('y', $current2->getAttribute('x'), 'the middleware attribute should survive');
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
        $sd1 = $ctx->getContainer()->get(\Quiote\Execution\SlotDispatcher::class);
        $sd2 = $ctx->getContainer()->get(\Quiote\Execution\SlotDispatcher::class);
        $this->assertSame($sd1, $sd2, 'SlotDispatcher should be cached and identical');
        $this->assertInstanceOf(\Quiote\Execution\SlotDispatcher::class, $sd1);
    }

    public function testControllerRecreatedAfterResetAndShutdownSequenceOrderMaintained(): void
    {
        $ctx = $this->ctx();
        $this->injectLogger($ctx);
        $ro = new ReflectionObject($ctx);
        // Force controller creation via internal initialize path if not created yet
        $controllerProp = $ro->getProperty('controller');

        $controller1 = $controllerProp->getValue($ctx);
        if ($controller1 === null) {
            // Invoke createInstanceFor if factory info stored in factories array
            try {
                $controller1 = $ctx->getContainer()->get(\Quiote\Controller\Controller::class);
            } catch (\Throwable) {
            }
            // Fallback: direct instantiation
            if ($controller1 === null) {
                $fi = $this->declarationFor($ctx, 'controller');
                $controller1 = new $fi['class']();
                if (is_callable([$controller1, 'initialize'])) {
                    $controller1->initialize($ctx, $fi['parameters']);
                }
                $controllerProp->setValue($ctx, $controller1);
            }
        }
        $this->assertInstanceOf(\Quiote\Controller\Controller::class, $controller1, 'Controller should be created');
        // Populate the sequence ordering by making the user exist.
        $ctx->getUser();
        $ctx->reset();
        // After reset the controller object should remain (not nulled in reset) but may be reset()
        $controller2 = $controllerProp->getValue($ctx);
        $this->assertSame($controller1, $controller2, 'Controller instance should persist across reset (reset() called but not replaced)');

        // A user must still be registered in the sequence after the reset -- reset() nulls the
        // context's property but leaves the entry, which is what getUser() then splices over.
        $users = array_filter(
            $ctx->getShutdownSequence()->all(),
            static fn(object $component): bool => $component instanceof \Quiote\User\User,
        );
        $this->assertNotEmpty($users, 'the user must be registered in the shutdown sequence');
    }

    public function testTranslationManagerPreservedFlagAndNullWhenDisabled(): void
    {
        $ctx = $this->ctx();
        $ro = new ReflectionObject($ctx);
        // Ensure translation disabled to assert null return
        Config::set('core.use_translation', false);
        $this->assertNull($ctx->getContainer()->tryGet(\Quiote\Translation\TranslationManager::class), 'Translation manager should be null when translations disabled');
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
        // Force initial creation (may still be null if not requested previously)
        $dbmProp = $ro->getProperty('databaseManager');

        $dbm1 = $dbmProp->getValue($ctx);
        if (!$dbm1) {
            $fi = $this->declarationFor($ctx, 'database_manager');
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


        $handler = $ctx->getRequestHandler();
        $this->assertFalse($handler->hasPipeline(), 'no pipeline before the first handle()');

        $ctx->handle(new ServerRequest('GET', '/kernel')); // builds pipeline

        $this->assertTrue($handler->hasPipeline(), 'the pipeline is built by handle()');
        $kernel = $handler->pipeline();
        $debugStackBefore = $kernel->debugStack();
        $this->assertNotEmpty($debugStackBefore, 'Middleware debug stack should be populated');
        $ctx->reset(); // kernel is kept alive; reset() no longer calls psrKernel->reset() (avoids pipeline rebuild)
        // Reinject mock storage after reset since reset nulls storage
        $kernelAfter = $handler->pipeline();
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

        $ctx->getUser();

        $ctx->reset();
        $ctx->getUser(); // recreate user
        $ctx->reset();
        $ctx->getUser(); // recreate again

        // Exactly one, not "not too many": replaceRole() removes every instance of the role before
        // splicing the replacement in, so a stale user can never sit alongside the live one and be
        // the thing whose authentication state gets persisted.
        $users = array_filter(
            $ctx->getShutdownSequence()->all(),
            static fn(object $component): bool => $component instanceof \Quiote\User\User,
        );
        $this->assertCount(1, $users, 'the shutdown sequence must hold exactly one user');
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
