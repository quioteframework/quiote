<?php

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Quiote\Controller\Controller;
use Quiote\Middleware\DispatchMiddleware;
use Quiote\Response\WebResponse;

/**
 * WebResponse::send() stages a response instead of emitting one, because transport
 * is the runtime emitter's job and doing it inline only ever worked under a SAPI.
 * That only holds together if the pipeline actually picks the staged response up --
 * otherwise send() would silently discard everything the action produced.
 *
 * So this covers the join: an action that called send() must have exactly its
 * response returned, and one that never called it must still get the normal
 * rebuild-from-the-global-response path.
 */
class DispatchMiddlewareStagedResponseTest extends TestCase
{
    private function makeController(WebResponse $globalResp): Controller
    {
        $ctx = $this->createStub(\Quiote\Context::class);
        $container = new \Quiote\DI\Container();
        $container->set(\Quiote\Request\WebRequest::class, new \Quiote\Request\WebRequest(), \Quiote\DI\Container::SCOPE_REQUEST);
        $ctx->method('getContainer')->willReturn($container);

        $controller = new class($globalResp) extends Controller {
            public function __construct(private readonly WebResponse $gResp) {}
            #[\Override]
            public function getGlobalResponse(): WebResponse
            {
                return $this->gResp;
            }
        };

        $ref = new ReflectionClass($controller);
        if ($ref->hasProperty('context')) {
            $ref->getProperty('context')->setValue($controller, $ctx);
        }

        return $controller;
    }

    private function buildPsrResponse(Controller $controller, string $content): ResponseInterface
    {
        $middleware = new DispatchMiddleware($controller);
        $method = new ReflectionClass($middleware)->getMethod('buildPsrResponse');

        $response = $method->invoke($middleware, $content, 'html', false, false, null);
        $this->assertInstanceOf(ResponseInterface::class, $response);

        return $response;
    }

    public function testAStagedResponseIsReturnedVerbatim(): void
    {
        $globalResp = new WebResponse();
        $globalResp->setContent('staged-body');
        $globalResp->setHttpStatusCode(404);
        $globalResp->setHttpHeader('X-From-Action', 'yes');
        // Explicit attributes: an uninitialized WebResponse has no cookie_* parameter
        // defaults to fall back on.
        $globalResp->setCookie('sid', 'abc', 0, '/', 'example.test', false, true, false);
        $globalResp->send();

        $response = $this->buildPsrResponse($this->makeController($globalResp), 'rebuilt-body');

        $this->assertSame('staged-body', (string) $response->getBody(), 'the staged body must win over the rebuilt one');
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('yes', $response->getHeaderLine('X-From-Action'));
        $this->assertStringStartsWith('sid=abc', $response->getHeader('Set-Cookie')[0] ?? '');
    }

    public function testWithoutSendTheResponseIsBuiltFromTheActionContentAsBefore(): void
    {
        $globalResp = new WebResponse();
        $globalResp->setHttpHeader('X-From-Action', 'yes');

        $response = $this->buildPsrResponse($this->makeController($globalResp), 'rebuilt-body');

        $this->assertSame('rebuilt-body', (string) $response->getBody());
        $this->assertSame('yes', $response->getHeaderLine('X-From-Action'));
    }
}
