<?php

use Nyholm\Psr7\ServerRequest;
use Quiote\Execution\ActionDescriptor;
use Quiote\Middleware\ValidationMiddleware;
use Quiote\Request\WebRequest;
use Quiote\Testing\UnitTestCase;

/**
 * Unit coverage for the steps ValidationMiddleware::process() delegates to. Each is
 * exercised directly: driving them through the full pipeline needs a wired
 * module/view/validator stack whose setup cost buys nothing for logic this self-contained.
 */
class ValidationMiddlewareSeamsTest extends UnitTestCase
{
    private function middleware(): ValidationMiddleware
    {
        return new ValidationMiddleware($this->getContext()->getContainer()->get(\Quiote\Controller\Controller::class));
    }

    /** @param array<mixed> $args */
    private function invoke(object $object, string $method, array $args): mixed
    {
        return (new ReflectionMethod($object, $method))->invoke($object, ...$args);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function methodTokenProvider(): array
    {
        return [
            'semantic read passes through' => ['read', 'read', 'Read'],
            'semantic write passes through' => ['write', 'write', 'Write'],
            'semantic update passes through' => ['update', 'update', 'Update'],
            'uppercase semantic token is lowercased' => ['READ', 'read', 'Read'],
            'GET maps to read' => ['GET', 'read', 'Read'],
            'POST maps to write' => ['POST', 'write', 'Write'],
            'empty method defaults to GET' => ['', 'read', 'Read'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('methodTokenProvider')]
    public function testMethodTokens(string $method, string $expectedConfig, string $expectedSuffix): void
    {
        $tokens = $this->invoke($this->middleware(), 'methodTokens', [$method]);

        $this->assertSame(['config' => $expectedConfig, 'suffix' => $expectedSuffix], $tokens);
    }

    public function testPromoteRouteParamsMakesRouteValuesVisibleToValidators(): void
    {
        $middleware = $this->middleware();
        $webRequest = new WebRequest('GET', 'http://localhost/orders/7');
        $request = (new ServerRequest('GET', '/orders/7'))
            ->withAttribute('route_params', ['id' => '7', 'slug' => 'acme']);

        $promoted = $this->invoke($middleware, 'promoteRouteParams', [$request, $webRequest]);
        $this->assertInstanceOf(WebRequest::class, $promoted);

        // Promoted unvalidated: not reachable just because a route declared it...
        $this->assertFalse($promoted->hasParameter('id'));
        $this->assertSame([], $promoted->getParameters('runtime'));

        // ...but visible once a validator declares the name, which is what
        // ValidationManager does before running validators.
        $declared = $promoted->declareParameters(['id', 'slug']);
        $this->assertSame('7', $declared->getParameter('id'));
        $this->assertSame('acme', $declared->getParameter('slug'));
    }

    public function testPromoteRouteParamsSkipsInternalKeysAndArrays(): void
    {
        $middleware = $this->middleware();
        $webRequest = new WebRequest('GET', 'http://localhost/x');
        $request = (new ServerRequest('GET', '/x'))
            ->withAttribute('route_params', [
                '_route' => 'internal',
                '_controller' => 'internal',
                'nested' => ['not', 'promoted'],
                'ok' => 'yes',
            ]);

        $promoted = $this->invoke($middleware, 'promoteRouteParams', [$request, $webRequest]);
        $this->assertInstanceOf(WebRequest::class, $promoted);

        $declared = $promoted->declareParameters(['_route', '_controller', 'nested', 'ok']);
        $this->assertNull($declared->getParameter('_route', null));
        $this->assertNull($declared->getParameter('_controller', null));
        $this->assertNull($declared->getParameter('nested', null));
        $this->assertSame('yes', $declared->getParameter('ok'));
    }

    public function testPromoteRouteParamsDoesNotOverwriteQueryOrBody(): void
    {
        $middleware = $this->middleware();
        $webRequest = (new WebRequest('GET', 'http://localhost/x?id=from-query'))
            ->withQueryParams(['id' => 'from-query']);
        $request = (new ServerRequest('GET', '/x'))
            ->withAttribute('route_params', ['id' => 'from-route']);

        $promoted = $this->invoke($middleware, 'promoteRouteParams', [$request, $webRequest]);
        $this->assertInstanceOf(WebRequest::class, $promoted);

        // The route value was not promoted, so the query value is what a validator sees.
        $this->assertSame('from-query', $promoted->declareParameter('id')->getParameter('id'));
    }

    public function testPromoteRouteParamsIsANoOpWithoutRouteParams(): void
    {
        $middleware = $this->middleware();
        $webRequest = new WebRequest('GET', 'http://localhost/x');

        $this->assertSame(
            $webRequest,
            $this->invoke($middleware, 'promoteRouteParams', [new ServerRequest('GET', '/x'), $webRequest])
        );
        $this->assertSame(
            $webRequest,
            $this->invoke($middleware, 'promoteRouteParams', [
                (new ServerRequest('GET', '/x'))->withAttribute('route_params', []),
                $webRequest,
            ])
        );
    }

    /**
     * A non-WebRequest from the pipeline must be overlaid onto the canonical request rather
     * than replacing it: replacing would discard the validated-parameter whitelist strict
     * access checking depends on.
     */
    public function testAdoptPipelineRequestOverlaysAForeignPsrRequest(): void
    {
        $middleware = $this->middleware();
        $canonical = (new WebRequest('GET', 'http://localhost/original'))
            ->setParameter('kept', 'value');
        $pipeline = (new ServerRequest('POST', 'http://localhost/changed'))
            ->withQueryParams(['q' => '1'])
            ->withAttribute('carried', 'through');

        $adopted = $this->invoke($middleware, 'adoptPipelineRequest', [$pipeline, $canonical]);
        $this->assertInstanceOf(WebRequest::class, $adopted);

        $this->assertSame('POST', $adopted->getMethod());
        $this->assertSame('/changed', $adopted->getUri()->getPath());
        $this->assertSame(['q' => '1'], $adopted->getQueryParams());
        $this->assertSame('through', $adopted->getAttribute('carried'));
        $this->assertSame('value', $adopted->getParameter('kept'));
    }

    public function testAdoptPipelineRequestUsesAWebRequestDirectly(): void
    {
        $middleware = $this->middleware();
        $canonical = new WebRequest('GET', 'http://localhost/original');
        $pipeline = new WebRequest('GET', 'http://localhost/from-pipeline');

        $adopted = $this->invoke($middleware, 'adoptPipelineRequest', [$pipeline, $canonical]);

        $this->assertSame($pipeline, $adopted);
    }

    public function testAdoptPipelineRequestIsANoOpWhenBothAreTheSameObject(): void
    {
        $middleware = $this->middleware();
        $canonical = new WebRequest('GET', 'http://localhost/x');

        $this->assertSame(
            $canonical,
            $this->invoke($middleware, 'adoptPipelineRequest', [$canonical, $canonical])
        );
    }

    public function testClearParametersDropsRuntimeValuesAndPublishesTheResult(): void
    {
        $middleware = $this->middleware();
        $webRequest = (new WebRequest('GET', 'http://localhost/x'))->setParameter('gone', 'value');

        $cleared = $this->invoke($middleware, 'clearParameters', [$webRequest]);
        $this->assertInstanceOf(WebRequest::class, $cleared);

        $this->assertSame([], $cleared->getParameters('runtime'));
        $this->assertSame($cleared, $this->getContext()->getRequest());
    }

    /**
     * The detail header exposes internal field and validator structure, so it stays off
     * unless explicitly enabled.
     */
    public function testErrorDetailHeaderIsOffByDefault(): void
    {
        $middleware = $this->middleware();
        \Quiote\Config\Config::set('core.expose_validation_errors_header', false);
        $response = \Quiote\Http\Psr17::factory()->createResponse(400);

        $result = $this->invoke($middleware, 'withErrorDetailHeader', [$response, ['boom']]);
        $this->assertInstanceOf(\Psr\Http\Message\ResponseInterface::class, $result);

        $this->assertFalse($result->hasHeader('X-Quiote-Validation-Errors'));
    }

    public function testErrorDetailHeaderIsAttachedWhenEnabled(): void
    {
        $middleware = $this->middleware();
        \Quiote\Config\Config::set('core.expose_validation_errors_header', true);
        $response = \Quiote\Http\Psr17::factory()->createResponse(400);

        try {
            $result = $this->invoke($middleware, 'withErrorDetailHeader', [$response, ['boom']]);
            $this->assertInstanceOf(\Psr\Http\Message\ResponseInterface::class, $result);

            $encoded = $result->getHeaderLine('X-Quiote-Validation-Errors');
            $this->assertSame(['boom'], json_decode((string) base64_decode($encoded, true), true));
        } finally {
            \Quiote\Config\Config::set('core.expose_validation_errors_header', false);
        }
    }

    public function testErrorDetailHeaderIsSkippedForAnEmptyErrorList(): void
    {
        $middleware = $this->middleware();
        \Quiote\Config\Config::set('core.expose_validation_errors_header', true);
        $response = \Quiote\Http\Psr17::factory()->createResponse(400);

        try {
            $result = $this->invoke($middleware, 'withErrorDetailHeader', [$response, []]);
            $this->assertInstanceOf(\Psr\Http\Message\ResponseInterface::class, $result);

            $this->assertFalse($result->hasHeader('X-Quiote-Validation-Errors'));
        } finally {
            \Quiote\Config\Config::set('core.expose_validation_errors_header', false);
        }
    }

    public function testFailureContentTypeUsesTheProblemMediaTypeForASynthesizedDocument(): void
    {
        $middleware = $this->middleware();
        $controller = $this->getContext()->getContainer()->get(\Quiote\Controller\Controller::class);

        $result = $this->invoke($middleware, 'failureContentType', [$controller, 'json', true]);

        $this->assertSame('application/problem+json; charset=UTF-8', $result);
    }

    public function testFailureContentTypeHonoursAContentTypeTheViewSet(): void
    {
        $middleware = $this->middleware();
        $controller = $this->getContext()->getContainer()->get(\Quiote\Controller\Controller::class);
        $controller->getGlobalResponse()->setContentType('application/problem+json');

        try {
            $result = $this->invoke($middleware, 'failureContentType', [$controller, 'html', false]);

            $this->assertSame('application/problem+json', $result);
        } finally {
            $controller->getGlobalResponse()->setContentType('');
        }
    }

    public function testFailureContentTypeFallsBackToTheOutputTypeMime(): void
    {
        $middleware = $this->middleware();
        $controller = $this->getContext()->getContainer()->get(\Quiote\Controller\Controller::class);
        $controller->getGlobalResponse()->setContentType('');

        $result = $this->invoke($middleware, 'failureContentType', [$controller, 'html', false]);

        $this->assertIsString($result);
        $this->assertStringContainsString('html', $result);
    }
}
