<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quiote\Config\Config;
use Quiote\Context;
use Quiote\DevOverlay\DevOverlayMiddleware;
use Quiote\DevOverlay\Http\HtmlScriptInjector;
use Quiote\DevOverlay\Http\OverlayEndpoint;
use Quiote\DevOverlay\Report\ExceptionReportBuilder;
use Quiote\DevOverlay\Report\ExceptionReportStoreInterface;
use Quiote\DI\Container;
use Quiote\Logging\Level;
use Quiote\Logging\Log;
use Quiote\Logging\LogEvent;
use Quiote\Logging\Sink\SinkInterface;
use Quiote\Middleware\ErrorHandlingMiddleware;
use Quiote\Request\RequestState;
use Quiote\Request\WebRequest;
use Quiote\Support\Clock\FrozenClock;
use Quiote\Support\Random\RandomnessInterface;

/** Keeps every report in memory, and can be told to fail. */
final class DevOverlayInMemoryStore implements ExceptionReportStoreInterface
{
    /** @var array<string, array<string, mixed>> */
    public array $reports = [];

    public bool $fail = false;

    public function put(string $id, array $report): void
    {
        if ($this->fail) {
            throw new RuntimeException('disk full');
        }
        $this->reports[$id] = $report;
    }

    public function get(string $id): ?array
    {
        return $this->reports[$id] ?? null;
    }
}

final class DevOverlayCapturingSink implements SinkInterface
{
    /** @var list<LogEvent> */
    public array $captured = [];

    public function isEnabled(Level $level, string $category): bool
    {
        return true;
    }

    public function emit(LogEvent $event): void
    {
        $this->captured[] = $event;
    }

    public function flush(): void
    {
    }
}

final class DevOverlayMiddlewareTest extends TestCase
{
    private ?bool $developerExceptions = null;

    private DevOverlayInMemoryStore $store;

    #[Before]
    public function enableDeveloperExceptions(): void
    {
        $this->developerExceptions = Config::has('core.developer_exceptions') ? Config::getBool('core.developer_exceptions') : null;
        Config::set('core.developer_exceptions', true, true, false);
        $this->store = new DevOverlayInMemoryStore();
    }

    #[After]
    public function restoreConfig(): void
    {
        foreach (['dev_overlay.enabled', 'dev_overlay.path', 'dev_overlay.editor_url'] as $key) {
            Config::remove($key);
        }
        Config::remove('core.developer_exceptions');
        if ($this->developerExceptions !== null) {
            Config::set('core.developer_exceptions', $this->developerExceptions, true, false);
        }
        Log::reset();
    }

    private static function fixedId(): string
    {
        return str_repeat('01', 16);
    }

    /** A mutable RequestState, the way production wires it: publish() changes what current() answers. */
    private static function requestState(ServerRequestInterface $request): RequestState
    {
        $current = WebRequest::fromPsr($request);

        return new RequestState(
            static function () use (&$current): WebRequest {
                return $current;
            },
            static function (WebRequest|ServerRequestInterface $replacement) use (&$current): void {
                $current = $replacement instanceof WebRequest ? $replacement : WebRequest::fromPsr($replacement);
            },
        );
    }

    private function context(?RequestState $requestState): Context
    {
        $container = new Container();
        if ($requestState !== null) {
            $container->set(RequestState::class, $requestState);
        }
        $context = $this->createStub(Context::class);
        $context->method('getContainer')->willReturn($container);

        return $context;
    }

    private function middleware(Context $context): DevOverlayMiddleware
    {
        $factory = new Psr17Factory();
        $randomness = new class implements RandomnessInterface {
            public function bytes(int $length): string
            {
                return str_repeat("\x01", $length);
            }

            public function int(int $min, int $max): int
            {
                return $min;
            }
        };

        return new DevOverlayMiddleware(
            $context,
            $this->store,
            new ExceptionReportBuilder(FrozenClock::fromDateTime(new DateTimeImmutable('2026-10-30T12:00:00+00:00'))),
            new OverlayEndpoint($this->store, $factory, $factory),
            new HtmlScriptInjector(),
            $factory,
            $randomness,
        );
    }

    private static function handler(ResponseInterface $response): RequestHandlerInterface
    {
        return new class ($response) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }

    /** Publishes `$exception` on the RequestState the way ErrorHandlingMiddleware does, then answers `$response`. */
    private static function failingHandler(RequestState $requestState, Throwable $exception, ResponseInterface $response): RequestHandlerInterface
    {
        return new class ($requestState, $exception, $response) implements RequestHandlerInterface {
            public function __construct(
                private readonly RequestState $requestState,
                private readonly Throwable $exception,
                private readonly ResponseInterface $response,
            ) {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->requestState->publish($this->requestState->current()->withAttribute(Throwable::class, $this->exception));

                return $this->response;
            }
        };
    }

    private static function page(int $status = 200): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'text/html; charset=utf-8'], '<html><body><h1>Orders</h1></body></html>');
    }

    public function testInjectsTheOverlayIntoAnHtmlPage(): void
    {
        $request = new ServerRequest('GET', '/orders');

        $response = $this->middleware($this->context(self::requestState($request)))->process($request, self::handler(self::page()));

        $body = (string) $response->getBody();
        $this->assertStringContainsString('<script src="/_quiote/overlay/overlay.js" data-quiote-overlay data-endpoint="/_quiote/overlay" defer></script></body>', $body);
        $this->assertFalse($response->hasHeader(DevOverlayMiddleware::HEADER));
        $this->assertSame([], $this->store->reports);
    }

    public function testAFailedJsonRequestIsReportedThroughTheHeader(): void
    {
        $request = new ServerRequest('POST', '/api/orders');
        $requestState = self::requestState($request);
        $json = new Response(500, ['Content-Type' => 'application/json'], '{"error":"internal"}');

        $response = $this->middleware($this->context($requestState))
            ->process($request, self::failingHandler($requestState, new DomainException('stock went negative'), $json));

        $this->assertSame(self::fixedId(), $response->getHeaderLine(DevOverlayMiddleware::HEADER));
        $this->assertSame('{"error":"internal"}', (string) $response->getBody(), 'A JSON body is never rewritten.');
        $report = $this->store->reports[self::fixedId()];
        $this->assertSame(500, $report['status']);
        $this->assertSame(['method' => 'POST', 'uri' => '/api/orders'], $report['request']);
        $this->assertIsArray($report['exceptions']);
        $this->assertIsArray($report['exceptions'][0]);
        $this->assertSame(DomainException::class, $report['exceptions'][0]['class']);
        $this->assertSame('stock went negative', $report['exceptions'][0]['message']);
    }

    public function testAFailedPageCarriesItsOwnReportIdToTheOverlay(): void
    {
        $request = new ServerRequest('GET', '/orders');
        $requestState = self::requestState($request);

        $response = $this->middleware($this->context($requestState))
            ->process($request, self::failingHandler($requestState, new RuntimeException('boom'), self::page(500)));

        $this->assertSame(self::fixedId(), $response->getHeaderLine(DevOverlayMiddleware::HEADER));
        $this->assertStringContainsString('data-exception="' . self::fixedId() . '"', (string) $response->getBody());
    }

    public function testPicksUpTheExceptionTheRealErrorHandlingMiddlewarePublishes(): void
    {
        $request = new ServerRequest('GET', '/api/orders', ['Accept' => 'application/json']);
        $requestState = self::requestState($request);
        $context = $this->context($requestState);
        $throwing = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new LogicException('unreachable branch');
            }
        };
        $errorHandling = new class (new ErrorHandlingMiddleware(null, $context), $throwing) implements RequestHandlerInterface {
            public function __construct(
                private readonly ErrorHandlingMiddleware $middleware,
                private readonly RequestHandlerInterface $next,
            ) {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->middleware->process($request, $this->next);
            }
        };

        $response = $this->middleware($context)->process($request, $errorHandling);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(self::fixedId(), $response->getHeaderLine(DevOverlayMiddleware::HEADER));
        $exceptions = $this->store->reports[self::fixedId()]['exceptions'];
        $this->assertIsArray($exceptions);
        $this->assertIsArray($exceptions[0]);
        $this->assertSame(LogicException::class, $exceptions[0]['class']);
    }

    public function testServesItsOwnEndpointWithoutReachingTheApplication(): void
    {
        $this->store->reports[self::fixedId()] = ['id' => self::fixedId()];
        $neverCalled = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new LogicException('The application must not see overlay requests.');
            }
        };

        $response = $this->middleware($this->context(null))->process(new ServerRequest('GET', '/_quiote/overlay/' . self::fixedId()), $neverCalled);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['id' => self::fixedId()], json_decode((string) $response->getBody(), true));
    }

    public function testDoesNothingWithoutDeveloperExceptions(): void
    {
        Config::set('core.developer_exceptions', false, true, false);
        $request = new ServerRequest('GET', '/orders');
        $requestState = self::requestState($request);

        $response = $this->middleware($this->context($requestState))
            ->process($request, self::failingHandler($requestState, new RuntimeException('boom'), self::page(500)));

        $this->assertFalse($response->hasHeader(DevOverlayMiddleware::HEADER));
        $this->assertStringNotContainsString('data-quiote-overlay', (string) $response->getBody());
        $this->assertSame([], $this->store->reports);
    }

    public function testTheOverlayPathIsAnOrdinaryRouteWhenInactive(): void
    {
        Config::set('core.developer_exceptions', false, true, false);

        $response = $this->middleware($this->context(null))
            ->process(new ServerRequest('GET', '/_quiote/overlay/overlay.js'), self::handler(new Response(404)));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testDoesNothingWhenSwitchedOff(): void
    {
        Config::set('dev_overlay.enabled', false, true, false);

        $response = $this->middleware($this->context(null))->process(new ServerRequest('GET', '/'), self::handler(self::page()));

        $this->assertStringNotContainsString('data-quiote-overlay', (string) $response->getBody());
    }

    public function testPassesTheEditorUrlToTheScript(): void
    {
        Config::set('dev_overlay.editor_url', 'vscode://file/{file}:{line}', true, false);

        $response = $this->middleware($this->context(null))->process(new ServerRequest('GET', '/'), self::handler(self::page()));

        $this->assertStringContainsString('data-editor="vscode://file/{file}:{line}"', (string) $response->getBody());
    }

    public function testCorrectsAnExistingContentLength(): void
    {
        $page = self::page()->withHeader('Content-Length', '41');

        $response = $this->middleware($this->context(null))->process(new ServerRequest('GET', '/'), self::handler($page));

        $this->assertSame((string) strlen((string) $response->getBody()), $response->getHeaderLine('Content-Length'));
    }

    public function testLeavesNonDocumentResponsesAlone(): void
    {
        $middleware = $this->middleware($this->context(null));
        $cases = [
            'undeclared type' => [new ServerRequest('GET', '/'), new Response(200, [], '<body></body>')],
            'json' => [new ServerRequest('GET', '/'), new Response(200, ['Content-Type' => 'application/json'], '"</body>"')],
            'compressed' => [new ServerRequest('GET', '/'), self::page()->withHeader('Content-Encoding', 'gzip')],
            'head' => [new ServerRequest('HEAD', '/'), self::page()],
            'fragment' => [new ServerRequest('GET', '/'), new Response(200, ['Content-Type' => 'text/html'], '<li>row</li>')],
        ];

        foreach ($cases as $name => [$request, $original]) {
            $response = $middleware->process($request, self::handler($original));
            $this->assertSame((string) $original->getBody(), (string) $response->getBody(), $name);
        }
    }

    public function testAStoreFailureIsLoggedAndTheResponseStillGoesOut(): void
    {
        $sink = new DevOverlayCapturingSink();
        Log::addSink($sink);
        $this->store->fail = true;
        $request = new ServerRequest('GET', '/api/orders');
        $requestState = self::requestState($request);
        $json = new Response(500, ['Content-Type' => 'application/json'], '{}');

        $response = $this->middleware($this->context($requestState))
            ->process($request, self::failingHandler($requestState, new RuntimeException('boom'), $json));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertFalse($response->hasHeader(DevOverlayMiddleware::HEADER));
        $warnings = array_values(array_filter($sink->captured, static fn(LogEvent $event): bool => $event->level === Level::Warning));
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('could not be stored for the developer overlay: disk full', $warnings[0]->renderMessage());
    }
}
