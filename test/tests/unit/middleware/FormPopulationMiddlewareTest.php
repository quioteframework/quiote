<?php

use Agavi\AgaviContext;
use Agavi\Middleware\FormPopulationMiddleware;
use Agavi\Request\AgaviWebRequest;
use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Util\FormPopulationConfig;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class FormPopulationMiddlewareTest extends AgaviUnitTestCase
{
    private ?AgaviContext $context = null;
    private \Agavi\Controller\AgaviController $controller;

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
            ->withAttribute('agavi.request_data', $webRequest)
            ->withParsedBody(['foo' => 'bar']);

        $factory = new Psr17Factory();
        $handler = new class($factory) implements RequestHandlerInterface {
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
            ->withAttribute('agavi.request_data', $webRequest);

        $factory = new Psr17Factory();
        $handler = new class($factory) implements RequestHandlerInterface {
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

    $config = FormPopulationConfig::get($webRequest);
    $this->assertArrayHasKey('force_request_uri', $config);
    $this->assertArrayHasKey('force_request_url', $config);
    $this->assertSame('/account/login', $config['force_request_uri']);
    $this->assertSame('https://example.test/account/login?via=mid', $config['force_request_url']);
    }

    private function makeIsolatedRequest(array $validated = []): AgaviWebRequest
    {
        $request = new AgaviWebRequest();
        $request->initialize($this->context);
        if($validated) {
            $request->enforceValidatedParameters($validated);
        }
        return $request;
    }
}

?>
