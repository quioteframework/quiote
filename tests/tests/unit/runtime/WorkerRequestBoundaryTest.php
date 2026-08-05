<?php

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Quiote\Context;
use Quiote\Logging\LogContext;
use Quiote\Runtime\ErrorResponseFactory;
use Quiote\Runtime\OutputCapture;
use Quiote\Runtime\Request\WorkerRequestFactory;
use Quiote\Runtime\Superglobals\SuperglobalBridge;
use Quiote\Runtime\Worker\FrankenPhpRuntime;
use Quiote\Runtime\Worker\WorkerLoop;
use Quiote\Runtime\Worker\WorkerRuntimeCapabilities;
use Quiote\Session\SessionManager;
use Quiote\User\ISecurityUser;
use Quiote\Util\WorkerManager;

/**
 * The request-boundary isolation guarantee for persistent workers, tested with
 * faults injected into the reset path.
 *
 * This is the shape of test the suite had no vocabulary for, and the reason a
 * cross-user authentication leak went unnoticed. Every other test here exercises
 * a single call; this one needs "request N, then a fault, then request N+1, in
 * one process" before the defect is even expressible.
 *
 * What went wrong: `Context::reset()` ran as one unguarded sequence with the two
 * assignments that clear identity at the end, after a controller reset, a user
 * flush, and `recycleConnections()` on the database manager. A throwable from any
 * of those -- a socket the peer closed at the request boundary being the ordinary
 * case -- aborted the method before identity was cleared, and
 * `WorkerManager::resetForNextRequest()` logged it and carried on. The next
 * request in that worker got a fresh session bag but the *previous request's*
 * authenticated `SecurityUser`, roles intact, because `getUser()` returns the
 * existing instance rather than rebuilding from the new session.
 *
 * So the assertions here are deliberately about the state a *later* request would
 * observe, not about reset()'s return or its exception. reset() is allowed to
 * fail loudly; it is not allowed to fail dirty.
 */
class WorkerRequestBoundaryTest extends TestCase
{
    private Context $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->context = Context::getInstance();
        $this->context->getContainer()->set(\Quiote\Session\SessionManager::class, new SessionManager(new InMemorySessionPersistence()));
        $this->context->getContainer()->set(\Quiote\Session\SessionBagInterface::class, new InMemorySessionBag(), \Quiote\DI\Container::SCOPE_REQUEST);
    }

    protected function tearDown(): void
    {
        // Whatever the test did to the shared context, hand the next one a clean
        // slate -- including undoing any injected fault.
        $this->restoreShutdownSequence();
        try {
            $this->context->reset();
        } catch (\Throwable) {
        }
        $this->context->getContainer()->unset(\Quiote\Session\SessionManager::class);
        LogContext::clear();
        parent::tearDown();
    }

    /** @var array<int, object>|null */
    private ?array $savedShutdownSequence = null;

    /**
     * The context is a process-wide singleton, so anything reached into here has to
     * be put back exactly as found -- not merely cleared. Nulling the database
     * manager instead of restoring it made a later, unrelated test skip itself for
     * want of a DatabaseManager, which is the quiet kind of cross-test pollution
     * that turns into "works alone, skips in CI".
     */
    private bool $savedDatabaseManagerCaptured = false;
    private mixed $savedDatabaseManager = null;

    /**
     * Splice a component into the context's shutdown sequence whose reset throws,
     * standing in for the real-world case: a database manager whose
     * recycleConnections() hits a socket the peer closed between requests.
     */
    private function injectFaultyShutdownComponent(\Throwable $toThrow): void
    {
        $sequence = $this->context->getShutdownSequence();
        $this->savedShutdownSequence ??= $sequence->all();

        $faulty = new class($toThrow) {
            public function __construct(private readonly \Throwable $toThrow)
            {
            }

            public function recycleConnections(): void
            {
                throw $this->toThrow;
            }

            public function shutdown(): void
            {
                throw $this->toThrow;
            }
        };

        // Prepended, so it runs before anything else in the sequence and the abort
        // happens as early as possible -- the worst case for the clears.
        $sequence->replaceAll([$faulty, ...$this->savedShutdownSequence]);

        // The loop only calls recycleConnections() on the component it recognises as
        // the database manager, so point that at the faulty one too.
        $databaseManager = new ReflectionProperty(Context::class, 'databaseManager');
        if (!$this->savedDatabaseManagerCaptured) {
            $this->savedDatabaseManager = $databaseManager->getValue($this->context);
            $this->savedDatabaseManagerCaptured = true;
        }
        $databaseManager->setValue($this->context, $faulty);
    }

    private function restoreShutdownSequence(): void
    {
        if ($this->savedShutdownSequence !== null) {
            $this->context->getShutdownSequence()->replaceAll($this->savedShutdownSequence);
            $this->savedShutdownSequence = null;
        }
        if ($this->savedDatabaseManagerCaptured) {
            (new ReflectionProperty(Context::class, 'databaseManager'))
                ->setValue($this->context, $this->savedDatabaseManager);
            $this->savedDatabaseManagerCaptured = false;
            $this->savedDatabaseManager = null;
        }
    }

    /** Put the context into the state a request that authenticated someone leaves behind. */
    private function authenticateAUser(): void
    {
        $user = $this->context->getUser();
        $this->assertInstanceOf(ISecurityUser::class, $user, 'this test needs a SecurityUser-shaped user factory');
        $user->setAuthenticated(true);

        // Re-read through the accessor rather than trusting $user: the point of the
        // whole file is what a later caller observes via getContext()->getUser().
        $reread = $this->context->getUser();
        $this->assertInstanceOf(ISecurityUser::class, $reread);
        $this->assertTrue($reread->isAuthenticated(), 'precondition: the user is authenticated');
    }

    /**
     * What a subsequent request in the same worker would see. Deliberately goes
     * through the public accessors, because that is what middleware and actions do.
     */
    private function assertNextRequestSeesNoIdentity(string $because): void
    {
        $user = $this->context->getUser();
        if ($user instanceof ISecurityUser) {
            $this->assertFalse(
                $user->isAuthenticated(),
                'the next request must not inherit an authenticated user: ' . $because
            );
        }
        $this->assertInstanceOf(
            \Quiote\Session\NullSessionBag::class,
            $this->context->getContainer()->get(\Quiote\Session\SessionBagInterface::class),
            'the next request must not inherit the previous request\'s session bag: ' . $because
        );
    }

    // --- the guarantee, with and without faults ---

    public function testACleanResetClearsIdentity(): void
    {
        $this->authenticateAUser();

        $this->context->reset();

        $this->assertNextRequestSeesNoIdentity('no fault was injected at all');
    }

    public function testIdentityIsClearedEvenWhenTheShutdownSequenceThrowsAnException(): void
    {
        $this->authenticateAUser();
        $this->injectFaultyShutdownComponent(new RuntimeException('connection recycle failed'));

        // reset() is allowed to propagate: failing loudly is fine, failing dirty is not.
        try {
            $this->context->reset();
            $this->fail('the injected fault should have propagated out of reset()');
        } catch (RuntimeException $e) {
            $this->assertSame('connection recycle failed', $e->getMessage());
        }

        $this->assertNextRequestSeesNoIdentity('an Exception aborted the shutdown sequence');
    }

    public function testIdentityIsClearedEvenWhenTheShutdownSequenceThrowsAnError(): void
    {
        // An Error, not an Exception: the old caller caught only Exception, so a
        // TypeError from a component's reset escaped the swallow entirely.
        $this->authenticateAUser();
        $this->injectFaultyShutdownComponent(new TypeError('broken component'));

        try {
            $this->context->reset();
            $this->fail('the injected Error should have propagated out of reset()');
        } catch (TypeError) {
        }

        $this->assertNextRequestSeesNoIdentity('an Error aborted the shutdown sequence');
    }

    public function testLoggingScopesAreClearedEvenWhenResetThrows(): void
    {
        // Same leak class, lower stakes: request N's rid/user in request N+1's log
        // lines. Cleared inside the same guarded block, so it must survive a fault.
        LogContext::push(['rid' => 'request-N', 'user' => 'victim']);
        $this->injectFaultyShutdownComponent(new RuntimeException('boom'));

        try {
            $this->context->reset();
        } catch (RuntimeException) {
        }

        $this->assertTrue(LogContext::isEmpty(), 'ambient log scopes must not survive the boundary');
        $this->assertSame([], LogContext::current());
    }

    public function testTheFlushIsReArmedEvenWhenResetThrows(): void
    {
        // If requestStateFlushed stayed true, the next request's SessionMiddleware
        // would think this request's flush had already been claimed and skip
        // persisting the user entirely.
        $this->authenticateAUser();
        $this->injectFaultyShutdownComponent(new RuntimeException('boom'));

        try {
            $this->context->reset();
        } catch (RuntimeException) {
        }

        $this->assertFalse(
            $this->context->getLifecycle()->requestStateFlushClaimed(),
            'the next request must be able to claim its own flush'
        );
    }

    // --- the caller: WorkerManager must not let a fault stop the boundary ---

    public function testWorkerManagerSwallowsAResetFaultAndLeavesNoIdentityBehind(): void
    {
        $this->authenticateAUser();
        $this->injectFaultyShutdownComponent(new RuntimeException('boom'));

        // Must not propagate: the worker has to survive the boundary and serve the
        // next request. The point is that surviving is now safe, because reset()
        // guarantees its own clears ran before the throwable left it.
        WorkerManager::resetForNextRequest($this->context->getName());

        $this->assertNextRequestSeesNoIdentity('WorkerManager continued past a reset fault');
    }

    public function testWorkerManagerSurvivesAnErrorNotJustAnException(): void
    {
        $this->authenticateAUser();
        $this->injectFaultyShutdownComponent(new TypeError('broken component'));

        WorkerManager::resetForNextRequest($this->context->getName());

        $this->assertNextRequestSeesNoIdentity('WorkerManager caught an Error, not only an Exception');
    }

    // --- the runtime loop: the boundary must run even when emitting fails ---

    private function makeLoop(Context $context, callable $handler): WorkerLoop
    {
        return new WorkerLoop(
            context: $context,
            requestFactory: new WorkerRequestFactory(trustForwardedHeaders: false),
            superglobals: new SuperglobalBridge(),
            output: new OutputCapture(OutputCapture::POLICY_APPEND),
            errors: new ErrorResponseFactory(),
            capabilities: WorkerRuntimeCapabilities::sapi(persistent: true),
            maxRequests: 1,
        );
    }

    public function testFrankenPhpRuntimeRunsTheRequestBoundaryWhenEmittingThrows(): void
    {
        // WorkerLoop::handle() catches throwables from the pipeline, but the emit()
        // call sits outside that try. A broken pipe or a client disconnect mid-stream
        // therefore escaped the loop body, and because afterRequest() was not in a
        // finally the boundary reset was skipped entirely -- state from this request
        // still installed on the context.
        $this->authenticateAUser();

        $emitter = new class implements \Quiote\Runtime\Emitter\ResponseEmitterInterface {
            public function emit(ResponseInterface $response): void
            {
                throw new RuntimeException('broken pipe');
            }

            public function supportsStreaming(): bool
            {
                return false;
            }
        };

        $loop = $this->makeLoop(
            $this->context,
            static fn(): ResponseInterface => (new Psr17Factory())->createResponse(200),
        );

        $runtime = new FrankenPhpRuntime(
            static function (callable $callback): bool {
                $callback();

                return false;
            },
            $emitter,
        );

        try {
            $runtime->run($loop);
            $this->fail('the emitter failure should have propagated');
        } catch (RuntimeException $e) {
            $this->assertSame('broken pipe', $e->getMessage());
        }

        $this->assertNextRequestSeesNoIdentity('the emitter threw after the response was produced');
    }

    public function testFrankenPhpRuntimeRunsTheRequestBoundaryOnTheHappyPath(): void
    {
        // Positive control: the finally must not be the only thing that ever runs it.
        $this->authenticateAUser();

        $emitter = new class implements \Quiote\Runtime\Emitter\ResponseEmitterInterface {
            public int $count = 0;

            public function emit(ResponseInterface $response): void
            {
                $this->count++;
            }

            public function supportsStreaming(): bool
            {
                return false;
            }
        };

        $loop = $this->makeLoop(
            $this->context,
            static fn(): ResponseInterface => (new Psr17Factory())->createResponse(200),
        );

        $runtime = new FrankenPhpRuntime(
            static function (callable $callback): bool {
                $callback();

                return false;
            },
            $emitter,
        );

        $runtime->run($loop);

        $this->assertSame(1, $emitter->count, 'the response was emitted');
        $this->assertNextRequestSeesNoIdentity('the request completed normally');
    }
}
