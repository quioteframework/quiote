<?php

use Quiote\Context;
use Quiote\Middleware\FormPopulationMiddleware;
use Quiote\Request\RequestState;
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
        $this->controller = $this->context->getContainer()->get(\Quiote\Controller\Controller::class);

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

    public function testPopulatesTheFormsNamedInThePublishedPopulateMap(): void
    {
        $this->publishCurrent(['populate' => ['record' => ['foo' => 'stored']]]);

        $content = $this->populate(new ServerRequest('GET', 'https://example.test/form'), self::page());

        $this->assertStringContainsString('<input type="text" name="foo" value="stored">', $content);
        $this->assertStringContainsString('<input type="text" name="q">', $content, 'a form the map does not name keeps its markup');
    }

    public function testPopulateTrueFillsTheFormsFromTheCurrentRequestsParameters(): void
    {
        $this->publishCurrent(['populate' => true], ['foo' => 'validated'], 'POST');

        // The PSR body carries a different value: population reads the current request, which
        // validation has already pruned, never the raw submission.
        $request = (new ServerRequest('POST', 'https://example.test/form'))->withParsedBody(['foo' => 'raw', 'q' => 'raw']);
        $content = $this->populate($request, self::page());

        $this->assertStringContainsString('value="validated"', $content);
        $this->assertStringNotContainsString('raw', $content);
    }

    public function testWithoutPopulationConfigTheResponseIsLeftAsRendered(): void
    {
        $this->publishCurrent([], ['foo' => 'from-the-request']);

        $content = $this->populate(new ServerRequest('GET', 'https://example.test/form?foo=from-the-request'), self::page());

        $this->assertSame(self::page(), $content);
    }

    public function testAPopulateOfFalseIsLeftAsRendered(): void
    {
        $this->publishCurrent(['populate' => false, 'methods' => []], ['foo' => 'from-the-request']);

        $this->assertSame(self::page(), $this->populate(new ServerRequest('POST', 'https://example.test/form'), self::page()));
    }

    public function testAMethodsListNamingTheRequestMethodFillsFromTheRequest(): void
    {
        $this->publishCurrent(['methods' => ['write']], ['foo' => 'posted'], 'POST');

        $content = $this->populate(new ServerRequest('POST', 'https://example.test/form'), self::page());

        $this->assertStringContainsString('value="posted"', $content);
    }

    public function testAMethodsListNotNamingTheRequestMethodLeavesTheFormsAlone(): void
    {
        $this->publishCurrent(['methods' => ['write']], ['foo' => 'queried'], 'GET');

        $content = $this->populate(new ServerRequest('GET', 'https://example.test/form'), self::page());

        $this->assertStringNotContainsString('queried', $content);
    }

    public function testSetsTheForceRequestDefaultsOnTheCurrentRequest(): void
    {
        $this->publishCurrent(['populate' => ['record' => ['foo' => 'stored']]]);

        $this->populate(new ServerRequest('POST', 'https://example.test/account/login?via=mid'), self::page());

        $config = FormPopulationConfig::get($this->requestState()->current());
        $this->assertSame('/account/login', $config['force_request_uri'] ?? null);
        $this->assertSame('https://example.test/account/login?via=mid', $config['force_request_url'] ?? null);
    }

    public function testSkipsPopulationForNonHtmlContentType(): void
    {
        $this->publishCurrent(['populate' => ['record' => ['foo' => 'stored']]]);
        // Contains a literal "<form" so if the DOM engine ran against it regardless of
        // Content-Type, the assertion below would catch it.
        $json = '{"note":"<form id=\\"record\\" action=\\"/form\\"><input name=\\"foo\\"></form>"}';

        $content = $this->populate(new ServerRequest('GET', 'https://example.test/form'), $json, 'application/json');

        $this->assertSame($json, $content);
    }

    public function testSkipsPopulationWhenTheHtmlHasNoForm(): void
    {
        $this->publishCurrent(['populate' => ['record' => ['foo' => 'stored']]]);
        $html = '<!DOCTYPE html><html><body><p>Hello</p></body></html>';

        $this->assertSame($html, $this->populate(new ServerRequest('GET', 'https://example.test/plain'), $html));
    }

    public function testAnExceptionFromTheHandlerPropagates(): void
    {
        $middleware = new FormPopulationMiddleware($this->controller);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \RuntimeException('dispatch failed');
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('dispatch failed');
        $middleware->process(new ServerRequest('GET', 'https://example.test/form'), $handler);
    }

    private static function page(): string
    {
        return '<!DOCTYPE html><html><body>'
            . '<form id="record" action="/form"><input type="text" name="foo"></form>'
            . '<form id="search" action="/search"><input type="text" name="q"></form>'
            . '</body></html>';
    }

    private function requestState(): RequestState
    {
        return $this->context()->getContainer()->get(RequestState::class);
    }

    /**
     * Publishes the request the action and its view would have left behind.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $parameters
     */
    private function publishCurrent(array $config, array $parameters = [], string $method = 'GET'): void
    {
        $request = $this->makeIsolatedRequest()->withMethod($method)->withParameters($parameters);
        if ($config !== []) {
            $merged = FormPopulationConfig::merge($request, $config);
            $this->assertInstanceOf(WebRequest::class, $merged);
            $request = $merged;
        }
        $this->requestState()->publish($request);
    }

    private function populate(ServerRequestInterface $request, string $body, string $contentType = 'text/html'): string
    {
        $factory = new Psr17Factory();
        $handler = new readonly class($factory, $body, $contentType) implements RequestHandlerInterface {
            public function __construct(private Psr17Factory $factory, private string $body, private string $contentType) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->factory->createResponse(200)
                    ->withHeader('Content-Type', $this->contentType)
                    ->withBody($this->factory->createStream($this->body));
            }
        };

        $response = (new FormPopulationMiddleware($this->controller))->process($request, $handler);
        $response->getBody()->rewind();
        return $response->getBody()->getContents();
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
