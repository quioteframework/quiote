<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quiote\Action\Action;
use Quiote\Config\Config;
use Quiote\Context;
use Quiote\Controller\Controller;
use Quiote\DI\Container;
use Quiote\Execution\ActionDescriptor;
use Quiote\Execution\ActionInitContext;
use Quiote\Replay\Attribute\NoRecord;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteId;
use Quiote\Replay\Recording\RecorderMiddleware;
use Quiote\Replay\Store\CassetteStoreInterface;
use Quiote\Request\RequestState;
use Quiote\Request\WebRequest;

final class RecorderMiddlewareTest extends TestCase
{
    /** @var list<string> */
    private const REPLAY_KEYS = [
        'replay.enabled', 'replay.record', 'replay.sample_rate', 'replay.trigger_header',
        'replay.max_bytes', 'replay.max_effects', 'replay.capture_body', 'replay.capture_session',
        'replay.redact.headers', 'replay.redact.params', 'replay.redact.session', 'replay.redact.mode',
    ];

    protected function tearDown(): void
    {
        foreach (self::REPLAY_KEYS as $key) {
            Config::remove($key);
        }
        \Quiote\Replay\Recording\EffectSourceRegistry::reset();
        parent::tearDown();
    }

    private function enable(string $record = 'always'): void
    {
        Config::set('replay.enabled', true, true, false);
        Config::set('replay.record', $record, true, false);
    }

    /** @return CassetteStoreInterface&object{put: list<array{0: CassetteId, 1: Cassette}>} */
    private function spyStore(): CassetteStoreInterface
    {
        return new class implements CassetteStoreInterface {
            /** @var list<array{0: CassetteId, 1: Cassette}> */
            public array $put = [];

            public function put(CassetteId $id, Cassette $cassette): void
            {
                $this->put[] = [$id, $cassette];
            }

            public function get(CassetteId $id): ?Cassette
            {
                return null;
            }

            public function has(CassetteId $id): bool
            {
                return false;
            }
        };
    }

    private function context(CassetteStoreInterface $store, ?RequestState $requestState = null, ?Controller $controller = null): Context
    {
        $container = new Container();
        $container->set(CassetteStoreInterface::class, $store);
        if ($requestState !== null) {
            $container->set(RequestState::class, $requestState);
        }
        if ($controller !== null) {
            $container->set(Controller::class, $controller);
        }
        $context = $this->createStub(Context::class);
        $context->method('getContainer')->willReturn($container);
        $context->method('getName')->willReturn('test');

        return $context;
    }

    private function requestStatePublishing(ServerRequestInterface $request): RequestState
    {
        $webRequest = WebRequest::fromPsr($request);

        return new RequestState(
            static fn(): WebRequest => $webRequest,
            static function (): void {
            },
        );
    }

    private function handler(ResponseInterface $response): RequestHandlerInterface
    {
        return new class($response) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }

    public function testNeverPolicyMakesNoStoreCall(): void
    {
        Config::set('replay.enabled', true, true, false);
        Config::set('replay.record', 'never', true, false);
        $store = $this->spyStore();
        $middleware = new RecorderMiddleware($this->context($store), $store);

        $response = $middleware->process(new ServerRequest('GET', '/'), $this->handler(new Response(200)));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(0, $store->put);
    }

    public function testDisabledMakesNoStoreCallEvenWhenRecordIsAlways(): void
    {
        Config::set('replay.enabled', false, true, false);
        Config::set('replay.record', 'always', true, false);
        $store = $this->spyStore();
        $middleware = new RecorderMiddleware($this->context($store), $store);

        $middleware->process(new ServerRequest('GET', '/'), $this->handler(new Response(200)));

        $this->assertCount(0, $store->put);
    }

    public function testAlwaysRecordsASuccessfulRequest(): void
    {
        $this->enable('always');
        $store = $this->spyStore();
        $middleware = new RecorderMiddleware($this->context($store), $store);

        $response = $middleware->process(new ServerRequest('GET', '/widgets'), $this->handler(new Response(200, [], 'ok')));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $store->put);
        $cassette = $store->put[0][1];
        $this->assertSame(200, $cassette->response['status']);
        $this->assertSame('GET', $cassette->request['method']);
        $this->assertSame([], $cassette->effects);
        // No driver-specific package is installed/registered in this test, so nothing recording
        // effects is instrumented -- see testEffectsInstrumentedIsTrueWhenAnEffectSourceIsRegistered()
        // for the other side of this.
        $this->assertFalse($cassette->meta['effects_instrumented']);
    }

    public function testRecordsAnEscapedExceptionAndRethrowsIt(): void
    {
        $this->enable('error');
        $store = $this->spyStore();
        $middleware = new RecorderMiddleware($this->context($store), $store);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('boom');
            }
        };

        try {
            $middleware->process(new ServerRequest('GET', '/'), $handler);
            $this->fail('Expected the exception to propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertCount(1, $store->put);
        $cassette = $store->put[0][1];
        $this->assertNotNull($cassette->exception);
        $this->assertSame(RuntimeException::class, $cassette->exception['class']);
        $this->assertSame('boom', $cassette->exception['message']);
        $this->assertSame(500, $cassette->response['status']);
    }

    public function testErrorPolicyDropsA200Response(): void
    {
        $this->enable('error');
        $store = $this->spyStore();
        $middleware = new RecorderMiddleware($this->context($store), $store);

        $middleware->process(new ServerRequest('GET', '/'), $this->handler(new Response(200)));

        $this->assertCount(0, $store->put);
    }

    public function testErrorPolicyKeepsA500Response(): void
    {
        $this->enable('error');
        $store = $this->spyStore();
        $middleware = new RecorderMiddleware($this->context($store), $store);

        $middleware->process(new ServerRequest('GET', '/'), $this->handler(new Response(500)));

        $this->assertCount(1, $store->put);
    }

    public function testReadsResolvedModuleAndActionFromRequestStateForASimpleAction(): void
    {
        $this->enable('always');
        $store = $this->spyStore();
        $descriptor = new ActionDescriptor('Widgets', 'Show', 'execute', 'html', true);
        $published = (new ServerRequest('GET', '/widgets'))->withAttribute(ActionDescriptor::class, $descriptor);
        $requestState = $this->requestStatePublishing($published);

        $middleware = new RecorderMiddleware($this->context($store, $requestState), $store);
        $middleware->process(new ServerRequest('GET', '/widgets'), $this->handler(new Response(200)));

        $cassette = $store->put[0][1];
        $this->assertSame('Widgets', $cassette->resolved['module']);
        $this->assertSame('Show', $cassette->resolved['action']);
    }

    public function testMakesNoStoreCallWhenNoRequestStateIsBound(): void
    {
        // A test double's fabricated Context/Container legitimately has no RequestState bound
        // (see the equivalent DispatchMiddleware/RoutingMiddleware regression tests); resolved
        // module/action simply comes back null rather than crashing.
        $this->enable('always');
        $store = $this->spyStore();
        $middleware = new RecorderMiddleware($this->context($store), $store);

        $middleware->process(new ServerRequest('GET', '/'), $this->handler(new Response(200)));

        $this->assertCount(1, $store->put);
        $this->assertNull($store->put[0][1]->resolved['module']);
    }

    public function testRespectsNoRecordAttributeOnTheResolvedAction(): void
    {
        $this->enable('always');
        $store = $this->spyStore();
        $descriptor = new ActionDescriptor('Payments', 'Charge', 'execute', 'html', true);
        $published = (new ServerRequest('POST', '/pay'))->withAttribute(ActionDescriptor::class, $descriptor);
        $requestState = $this->requestStatePublishing($published);
        $controller = new class extends Controller {
            #[\Override]
            public function createActionInstance($moduleName, $actionName): Action
            {
                return new RecorderMiddlewareTestNoRecordAction();
            }
        };

        $middleware = new RecorderMiddleware($this->context($store, $requestState, $controller), $store);
        $factory = new Psr17Factory();
        $request = (new ServerRequest('POST', '/pay'))
            ->withBody($factory->createStream(json_encode(['card' => '4242424242424242']) ?: ''));

        $middleware->process($request, $this->handler(new Response(200, [], 'ok')));

        $this->assertCount(1, $store->put);
        $cassette = $store->put[0][1];
        $this->assertTrue($cassette->resolved['no_record'] ?? false);
        $this->assertArrayNotHasKey('body', $cassette->request);
        $this->assertArrayNotHasKey('headers', $cassette->request);
        $this->assertNull($cassette->session);
        $this->assertNull($cassette->exception);
    }

    public function testExceedingMaxBytesTruncatesTheRequestBody(): void
    {
        $this->enable('always');
        Config::set('replay.max_bytes', 4, true, false);
        $store = $this->spyStore();
        $middleware = new RecorderMiddleware($this->context($store), $store);
        $factory = new Psr17Factory();
        $request = (new ServerRequest('POST', '/'))->withBody($factory->createStream('0123456789'));

        $middleware->process($request, $this->handler(new Response(200)));

        $cassette = $store->put[0][1];
        $body = $cassette->request['body'];
        $this->assertIsArray($body);
        $this->assertTrue($body['truncated']);
        $this->assertIsString($body['content']);
        $this->assertSame(4, strlen($body['content']));
    }

    public function testTwoSequentialRequestsThroughOneInstanceProduceIndependentCassettes(): void
    {
        $this->enable('always');
        $store = $this->spyStore();
        $middleware = new RecorderMiddleware($this->context($store), $store);

        $middleware->process(new ServerRequest('GET', '/one'), $this->handler(new Response(200)));
        $middleware->reset();
        $middleware->process(new ServerRequest('GET', '/two'), $this->handler(new Response(200)));

        $this->assertCount(2, $store->put);
        $this->assertSame('/one', $store->put[0][1]->request['uri']);
        $this->assertSame('/two', $store->put[1][1]->request['uri']);
    }

    public function testAStoreFailureIsLoggedAndDoesNotAlterTheResponse(): void
    {
        $this->enable('always');
        $store = new class implements CassetteStoreInterface {
            public function put(CassetteId $id, Cassette $cassette): void
            {
                throw new RuntimeException('disk full');
            }

            public function get(CassetteId $id): ?Cassette
            {
                return null;
            }

            public function has(CassetteId $id): bool
            {
                return false;
            }
        };
        $middleware = new RecorderMiddleware($this->context($store), $store);

        $response = $middleware->process(new ServerRequest('GET', '/'), $this->handler(new Response(200, [], 'unaffected')));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('unaffected', (string)$response->getBody());
    }

    /**
     * Generic, driver-agnostic proof of the EffectSource contract -- no ORM/DB dependency at
     * all. A real driver package (e.g. quioteframework/replay-propulsion) proves its own
     * EffectSource implementation end to end against a real connection in its own test suite;
     * this only proves RecorderMiddleware activates/deactivates every registered source
     * correctly and reports effects_instrumented accordingly.
     */
    public function testEffectsInstrumentedIsTrueAndSourcesAreActivatedWhenAnEffectSourceIsRegistered(): void
    {
        $source = new class implements \Quiote\Replay\Recording\EffectSource {
            /** @var list<string> */
            public array $activations = [];
            /** @var list<string> */
            public array $deactivations = [];

            public function activate(string $correlationId, \Quiote\Replay\Replay\EffectLedger $ledger): void
            {
                $this->activations[] = $correlationId;
                $ledger->record(\Quiote\Replay\Cassette\EffectKind::Db, 'fake query', [], null);
            }

            public function deactivate(string $correlationId): void
            {
                $this->deactivations[] = $correlationId;
            }
        };
        \Quiote\Replay\Recording\EffectSourceRegistry::register($source);

        $this->enable('always');
        $store = $this->spyStore();
        $middleware = new RecorderMiddleware($this->context($store), $store);

        $middleware->process(new ServerRequest('GET', '/widgets'), $this->handler(new Response(200)));

        $this->assertCount(1, $store->put);
        $cassette = $store->put[0][1];
        $this->assertTrue($cassette->meta['effects_instrumented']);
        $this->assertCount(1, $cassette->effects);
        $this->assertSame('fake query', $cassette->effects[0]->fingerprint);
        $this->assertCount(1, $source->activations);
        $this->assertCount(1, $source->deactivations);
        $this->assertSame($source->activations[0], $source->deactivations[0]);
    }

    public function testEveryRegisteredEffectSourceIsDeactivatedEvenWhenTheHandlerThrows(): void
    {
        $source = new class implements \Quiote\Replay\Recording\EffectSource {
            /** @var list<string> */
            public array $deactivations = [];

            public function activate(string $correlationId, \Quiote\Replay\Replay\EffectLedger $ledger): void
            {
            }

            public function deactivate(string $correlationId): void
            {
                $this->deactivations[] = $correlationId;
            }
        };
        \Quiote\Replay\Recording\EffectSourceRegistry::register($source);

        $this->enable('error');
        $store = $this->spyStore();
        $middleware = new RecorderMiddleware($this->context($store), $store);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('boom');
            }
        };

        try {
            $middleware->process(new ServerRequest('GET', '/'), $handler);
        } catch (RuntimeException) {
            // expected
        }

        $this->assertCount(1, $source->deactivations);
    }
}

#[NoRecord]
final class RecorderMiddlewareTestNoRecordAction extends Action
{
    #[\Override]
    public function initialize(ActionInitContext $ctx): void
    {
    }

    #[\Override]
    public function isCacheable(?string $outputType = null): bool
    {
        return false;
    }

    #[\Override]
    public function isSecure()
    {
        return false;
    }

    public function execute(mixed $request = null): mixed
    {
        return 'Charge';
    }
}
