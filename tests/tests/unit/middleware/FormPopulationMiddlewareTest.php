<?php

use Quiote\Context;
use Quiote\Middleware\FormPopulationMiddleware;
use Quiote\Request\WebRequest;
use Quiote\Testing\UnitTestCase;
use Quiote\Util\FormPopulationConfig;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class FormPopulationMiddlewareTest extends UnitTestCase
{
    private ?Context $context = null;
    private \Quiote\Controller\Controller $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->context = $this->getContext();
        $this->controller = $this->context->getController();

        $globalResponse = $this->controller->getGlobalResponse();
        $globalResponse->clear();
        $globalResponse->setContent('');
        $globalResponse->setOutputType($this->controller->getOutputType());
    }

    /**
     * $context is reset to null in tearDown() so it's declared nullable, but
     * every test method runs after setUp() has populated it, so a null here
     * indicates a broken test fixture rather than a case to tolerate.
     */
    private function context(): Context
    {
        if ($this->context === null) {
            throw new \RuntimeException('Expected setUp() to have initialized the context.');
        }
        return $this->context;
    }

    protected function tearDown(): void
    {
        $globalResponse = $this->controller->getGlobalResponse();
        $globalResponse->clear();
        $globalResponse->setContent('');
        $this->context = null;
        unset($this->controller);
        parent::tearDown();
    }

    public function testMiddlewarePopulatesResponseBody(): void
    {
        $middleware = new FormPopulationMiddleware($this->controller);
        $webRequest = $this->makeIsolatedRequest(['foo']);

        $psrRequest = (new ServerRequest('POST', 'https://example.test/form'))
            ->withAttribute('quiote.request_data', $webRequest)
            ->withParsedBody(['foo' => 'bar']);

        $factory = new Psr17Factory();
        $handler = new readonly class($factory) implements RequestHandlerInterface {
            public function __construct(private Psr17Factory $factory) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $html = '<!DOCTYPE html><html><body><form action="/form">'
                    . '<input type="text" name="foo"></form></body></html>';
                return $this->factory->createResponse(200)
                    ->withBody($this->factory->createStream($html));
            }
        };

        $response = $middleware->process($psrRequest, $handler);
        $response->getBody()->rewind();
        $content = $response->getBody()->getContents();

    $this->assertStringContainsString("value=\"bar\"", $content);
    }

    public function testMiddlewareSetsForceRequestDefaults(): void
    {
        $middleware = new FormPopulationMiddleware($this->controller);
        $webRequest = $this->makeIsolatedRequest();

        $psrRequest = (new ServerRequest('POST', 'https://example.test/account/login?via=mid'))
            ->withAttribute('quiote.request_data', $webRequest);

        $factory = new Psr17Factory();
        $handler = new readonly class($factory) implements RequestHandlerInterface {
            public function __construct(private Psr17Factory $factory) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->factory->createResponse(200)
                    ->withBody($this->factory->createStream('<html></html>'));
            }
        };

        $response = $middleware->process($psrRequest, $handler);
        $response->getBody()->rewind();
        $response->getBody()->getContents(); // drain to mimic consumption

    // WebRequest is immutable: the middleware's internal chain of with*()/setParameter()
    // calls produced new instances distinct from our local $webRequest. It re-syncs the
    // final instance into the context, so fetch it from there.
    $webRequest = $this->context()->getRequest();
    $config = FormPopulationConfig::get($webRequest);
    $this->assertArrayHasKey('force_request_uri', $config);
    $this->assertArrayHasKey('force_request_url', $config);
    $this->assertSame('/account/login', $config['force_request_uri']);
    $this->assertSame('https://example.test/account/login?via=mid', $config['force_request_url']);
    }

    public function testMiddlewareSkipsPopulationForNonHtmlContentType(): void
    {
        $middleware = new FormPopulationMiddleware($this->controller);
        $webRequest = $this->makeIsolatedRequest(['foo']);

        $psrRequest = (new ServerRequest('POST', 'https://example.test/form'))
            ->withAttribute('quiote.request_data', $webRequest)
            ->withParsedBody(['foo' => 'bar']);

        $factory = new Psr17Factory();
        $handler = new readonly class($factory) implements RequestHandlerInterface {
            public function __construct(private Psr17Factory $factory) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                // Contains a literal "<form" so if the DOM engine ran against it
                // regardless of Content-Type, the assertion below would catch it.
                $json = '{"note":"<form action=\"/form\"><input name=\"foo\"></form>"}';
                return $this->factory->createResponse(200)
                    ->withHeader('Content-Type', 'application/json')
                    ->withBody($this->factory->createStream($json));
            }
        };

        $response = $middleware->process($psrRequest, $handler);
        $response->getBody()->rewind();
        $content = $response->getBody()->getContents();

        $this->assertSame(
            '{"note":"<form action=\"/form\"><input name=\"foo\"></form>"}',
            $content
        );
    }

    public function testMiddlewareSkipsPopulationWhenHtmlHasNoForm(): void
    {
        $middleware = new FormPopulationMiddleware($this->controller);
        $webRequest = $this->makeIsolatedRequest(['foo']);

        $psrRequest = (new ServerRequest('GET', 'https://example.test/plain?foo=bar'))
            ->withAttribute('quiote.request_data', $webRequest);

        $factory = new Psr17Factory();
        $handler = new readonly class($factory) implements RequestHandlerInterface {
            public function __construct(private Psr17Factory $factory) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $html = '<!DOCTYPE html><html><body><p>Hello bar</p></body></html>';
                return $this->factory->createResponse(200)
                    ->withHeader('Content-Type', 'text/html')
                    ->withBody($this->factory->createStream($html));
            }
        };

        $response = $middleware->process($psrRequest, $handler);
        $response->getBody()->rewind();
        $content = $response->getBody()->getContents();

        $this->assertSame(
            '<!DOCTYPE html><html><body><p>Hello bar</p></body></html>',
            $content
        );
    }

    public function testMiddlewareMergesQueryBodyAndRouteParamsWithRouteParamsWinningOnConflict(): void
    {
        $middleware = new FormPopulationMiddleware($this->controller);
        $webRequest = $this->makeIsolatedRequest();

        $psrRequest = (new ServerRequest('POST', 'https://example.test/form?shared=query&onlyQuery=q'))
            ->withAttribute('quiote.request_data', $webRequest)
            ->withParsedBody(['shared' => 'body', 'onlyBody' => 'b'])
            ->withAttribute('route_params', ['shared' => 'route', 'onlyRoute' => 'r']);

        $factory = new Psr17Factory();
        $handler = new readonly class($factory) implements RequestHandlerInterface {
            public function __construct(private Psr17Factory $factory) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->factory->createResponse(200)
                    ->withBody($this->factory->createStream('<html></html>'));
            }
        };

        $response = $middleware->process($psrRequest, $handler);
        $response->getBody()->rewind();
        $response->getBody()->getContents(); // drain to mimic consumption

        // WebRequest is immutable, so fetch the instance the middleware re-synced into the context.
        $webRequest = $this->context()->getRequest();

        // route_params must win over parsed body, which must win over query params.
        $this->assertSame('route', $webRequest->getParameter('shared'));
        $this->assertSame('q', $webRequest->getParameter('onlyQuery'));
        $this->assertSame('b', $webRequest->getParameter('onlyBody'));
        $this->assertSame('r', $webRequest->getParameter('onlyRoute'));
    }

    /**
     * @param array<int, string> $validated
     */
    private function makeIsolatedRequest(array $validated = []): WebRequest
    {
        $request = new WebRequest();
        $request->initialize($this->context());
        if($validated) {
            $request = $request->enforceValidatedParameters($validated);
        }
        return $request;
    }
}

?>
