<?php

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quiote\Context;
use Quiote\Middleware\Config\MiddlewareConfigRegistry;
use Quiote\Middleware\MiddlewareCatalog;
use Quiote\Testing\HttpTestCase;

/**
 * Covers HttpTestCase's request-building/dispatch mechanics. Most cases use
 * MiddlewareCatalog::replaceCoreStack() (the same escape hatch
 * MiddlewareCoreStackOverrideTest exercises) so assertions are about what
 * HttpTestCase sent, not about the sandbox app's routing/rendering -- that
 * keeps this test deterministic and independent of the sandbox app's
 * module/view fixtures.
 */
class HttpTestCaseTest extends HttpTestCase
{
    /**
     * Reaches the protected getContext() to hand-build an edge-case request
     * (a deliberately malformed JSON body) that the fluent get()/post()/json()
     * helpers can't express by design.
     */
    private function handleRaw(ServerRequestInterface $request): ResponseInterface
    {
        return $this->getContext()->handle($request);
    }
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        MiddlewareCatalog::reset();
        MiddlewareConfigRegistry::reset();
    }

    #[\Override]
    protected function tearDown(): void
    {
        MiddlewareCatalog::reset();
        MiddlewareConfigRegistry::reset();
        parent::tearDown();
    }

    private static function echoMiddleware(): callable
    {
        return static fn(): MiddlewareInterface => new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $payload = json_encode([
                    'method' => $request->getMethod(),
                    'uri' => (string)$request->getUri(),
                    'body' => (string)$request->getBody(),
                    'contentType' => $request->getHeaderLine('Content-Type'),
                    'testHeader' => $request->getHeaderLine('X-Test-Header'),
                ]);
                return new Response(200, ['Content-Type' => 'application/json'], (string)$payload);
            }
        };
    }

    private function replaceStackWithEcho(): void
    {
        MiddlewareCatalog::replaceCoreStack(
            fn(Context $c) => [(self::echoMiddleware())()],
            MiddlewareCatalog::REPLACE_CORE_STACK_ACKNOWLEDGEMENT
        );
    }

    public function testGetDispatchesThroughFullPipeline(): void
    {
        $this->replaceStackWithEcho();
        $response = $this->get('/attr-routing');
        $response->assertOk();
        $response->assertJson(['method' => 'GET']);
    }

    public function testPostSendsFormEncodedBody(): void
    {
        $this->replaceStackWithEcho();
        $response = $this->post('/attr-routing', ['name' => 'Ada', 'role' => 'engineer']);
        $body = $response->json();
        $this->assertSame('POST', $body['method']);
        $this->assertSame('application/x-www-form-urlencoded', $body['contentType']);
        parse_str($body['body'], $decoded);
        $this->assertSame(['name' => 'Ada', 'role' => 'engineer'], $decoded);
    }

    public function testPutPatchDeleteUseFormEncoding(): void
    {
        $this->replaceStackWithEcho();
        foreach (['put' => 'PUT', 'patch' => 'PATCH', 'delete' => 'DELETE'] as $helper => $expectedMethod) {
            $response = $this->$helper('/attr-routing', ['x' => '1']);
            $this->assertSame($expectedMethod, $response->json()['method']);
        }
    }

    public function testJsonHelperSendsJsonEncodedBody(): void
    {
        $this->replaceStackWithEcho();
        $response = $this->json('POST', '/attr-routing', ['name' => 'Ada']);
        $body = $response->json();
        $this->assertSame('application/json', $body['contentType']);
        $this->assertSame(['name' => 'Ada'], json_decode($body['body'], true));
    }

    public function testCustomHeadersAreSent(): void
    {
        $this->replaceStackWithEcho();
        $response = $this->get('/attr-routing', ['X-Test-Header' => 'present']);
        $this->assertSame('present', $response->json()['testHeader']);
    }

    public function testInvalidJsonBodyIsRejectedByRealPipeline(): void
    {
        // No stack override here: exercise the real PayloadParsingMiddleware,
        // which returns 400 for strictly-invalid JSON before routing runs.
        $request = (new ServerRequest('POST', '/whatever', ['Content-Type' => 'application/json']))
            ->withBody(\Quiote\Http\Psr17::factory()->createStream('{not valid json'));
        $response = new \Quiote\Testing\Http\TestResponse($this->handleRaw($request));
        $response->assertStatus(400);
        $response->assertJson(['error' => 'invalid_json']);
    }
}
