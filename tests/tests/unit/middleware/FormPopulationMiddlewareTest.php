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
        if(method_exists($globalResponse, 'clear')) {
            $globalResponse->clear();
        }
        $globalResponse->setContent('');
        $globalResponse->setOutputType($this->controller->getOutputType());
    }

    protected function tearDown(): void
    {
        $globalResponse = $this->controller->getGlobalResponse();
        if(method_exists($globalResponse, 'clear')) {
            $globalResponse->clear();
        }
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
    $webRequest = $this->context->getRequest();
    $config = FormPopulationConfig::get($webRequest);
    $this->assertArrayHasKey('force_request_uri', $config);
    $this->assertArrayHasKey('force_request_url', $config);
    $this->assertSame('/account/login', $config['force_request_uri']);
    $this->assertSame('https://example.test/account/login?via=mid', $config['force_request_url']);
    }

    private function makeIsolatedRequest(array $validated = []): WebRequest
    {
        $request = new WebRequest();
        $request->initialize($this->context);
        if($validated) {
            $request = $request->enforceValidatedParameters($validated);
        }
        return $request;
    }
}

?>
