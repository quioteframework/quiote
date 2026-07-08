<?php

use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Context;
use Quiote\Middleware\DispatchMiddleware;
use Quiote\Execution\ActionDescriptor;
use Quiote\Execution\ExecutionState;
use Quiote\Execution\ValidationDecision;
use Quiote\Controller\Controller;

/**
 * Edge case coverage for DispatchMiddleware focusing on negative validation paths and 404 descriptor absence.
 * Caching branches remain outside scope and are exercised in dedicated cache tests (currently skipped).
 *
 * Run in a separate process: setUp() sets core.environment via a plain
 * Config::set() (readonly defaults to false), but the first
 * Context::getInstance()/Quiote::bootstrap() call anywhere in the process
 * afterward locks core.environment read-only at whatever value it currently
 * holds -- see DispatchMiddlewareDeeperCoverageTest's docblock for the full
 * story (this is the same landmine, found and fixed alongside it).
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class DispatchMiddlewareEdgeCasesTest extends TestCase
{
    private function ctx(): Context { return Context::getInstance(); }

    /**
     * @param array<string, mixed> $attrs
     * @param array<string, string|array<int, string>> $headers
     */
    private function makeReq(array $attrs = [], array $headers = []): ServerRequestInterface
    {
        $r = new ServerRequest('GET', 'http://localhost/edge', $headers);
        foreach ($attrs as $k => $v) { $r = $r->withAttribute($k, $v); }
        return $r;
    }

    public function setUp(): void
    {
        \Quiote\Config\Config::set('core.environment', 'development');
    }

    public function testReturns404WhenNoActionDescriptor(): void
    {
        $ctx = $this->ctx();
        $mw = new DispatchMiddleware($ctx->getController());
        $req = $this->makeReq(); // no ActionDescriptor attribute
    $resp = $mw->process($req, new class implements RequestHandlerInterface { public function handle(ServerRequestInterface $r): ResponseInterface { throw new RuntimeException('Handler invoked unexpectedly'); } });
        $this->assertSame(404, $resp->getStatusCode());
        $this->assertStringContainsString('Not Found', (string)$resp->getBody());
    }

    public function testNonSimpleActionMissingValidationMiddlewareProduces500(): void
    {
        $ctx = $this->ctx();
        $mw = new DispatchMiddleware($ctx->getController());
        // Non-simple descriptor (isSimple = false) and no validationDecision present (ExecutionState pending but not forwarded)
        $desc = new ActionDescriptor('sample', 'MissingValidation', 'execute', 'html', false);
        $execState = new ExecutionState();
        $execState->validationDecision = null; // force explicit null so constructor's default pending doesn't mislead logic
        $req = $this->makeReq([ExecutionState::class => $execState, ActionDescriptor::class => $desc]);
    $resp = $mw->process($req, new class implements RequestHandlerInterface { public function handle(ServerRequestInterface $r): ResponseInterface { throw new RuntimeException('Handler invoked unexpectedly'); } });
        $this->assertSame(500, $resp->getStatusCode());
        $this->assertSame('validation-middleware-missing', $resp->getHeaderLine('X-Quiote-Debug'));
        $this->assertSame('absent', $resp->getHeaderLine('X-Quiote-Validation-State'));
    }

    public function testNonSimpleActionFailedValidationReturns400(): void
    {
        $ctx = $this->ctx();
        $mw = new DispatchMiddleware($ctx->getController());
        $desc = new ActionDescriptor('sample', 'FailedValidation', 'execute', 'html', false);
        $execState = new ExecutionState();
        $execState->validationDecision = ValidationDecision::failed(['e1']);
        // Provide a viewName to trigger failed branch early return in processNonSimple
        $execState->viewName = 'Error';
        $req = $this->makeReq([ExecutionState::class => $execState, ActionDescriptor::class => $desc]);
    $resp = $mw->process($req, new class implements RequestHandlerInterface { public function handle(ServerRequestInterface $r): ResponseInterface { throw new RuntimeException('Handler invoked unexpectedly'); } });
        $this->assertSame(400, $resp->getStatusCode());
        $body = (string)$resp->getBody();
        $this->assertStringContainsString('Validation Failed', $body);
    }
}
