<?php
use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Agavi\AgaviContext;
use Agavi\Middleware\DispatchMiddleware;
use Agavi\Execution\ActionDescriptor;
use Agavi\Execution\ExecutionState;
use Agavi\Execution\SecurityDecision;
use Agavi\Execution\ValidationDecision;

/**
 * Deeper coverage for DispatchMiddleware: secure simple heuristic and forwarded pending validation path.
 */
class DispatchMiddlewareDeeperCoverageTest extends TestCase
{
    private function ctx(): AgaviContext { return AgaviContext::getInstance(); }
    private function makeReq(array $attrs = []): ServerRequestInterface { $r = new ServerRequest('GET', 'http://localhost/deeper'); foreach($attrs as $k=>$v){ $r = $r->withAttribute($k,$v);} return $r; }

    public function setUp(): void
    {
        Agavi\Config\AgaviConfig::set('core.environment','development');
        // ensure security enabled
        Agavi\Config\AgaviConfig::set('core.use_security', true);
    }

    public function testSecureSimpleActionHeuristicSetsSecurityDecisionAllow()
    {
        $ctx = $this->ctx();
        $user = $ctx->getUser();
        if(method_exists($user,'setAuthenticated')) { $user->setAuthenticated(true); }
        $mw = new DispatchMiddleware($ctx->getController());
        $desc = new ActionDescriptor('sample','SecureSimple','execute','html', true);
        $exec = new ExecutionState();
        $exec->validationDecision = ValidationDecision::passed();
        // Pre-populate descriptor convenience fields
        $exec->module = 'sample'; $exec->action = 'SecureSimple'; $exec->outputType = 'html';
        // SecurityDecision left null so heuristic branch attempts to set it
        $req = $this->makeReq([ExecutionState::class=>$exec, ActionDescriptor::class=>$desc]);
        $resp = $mw->process($req, new class implements RequestHandlerInterface { public function handle(ServerRequestInterface $r): ResponseInterface { throw new RuntimeException('should not'); } });
        $this->assertSame(200,$resp->getStatusCode());
        $this->assertSame(SecurityDecision::Allow, $exec->securityDecision, 'Heuristic should set securityDecision to Allow for secure action + authenticated user');
    }

    public function testForwardedPendingValidationSkips500()
    {
        $ctx = $this->ctx();
        $mw = new DispatchMiddleware($ctx->getController());
        $desc = new ActionDescriptor('sample','SecureNonSimple','read','html', false);
        $exec = new ExecutionState();
        $exec->validationDecision = ValidationDecision::pending();
        $exec->forwarded = true; // simulate security forward earlier allowing pending validation to proceed
        // Forward implies SecurityMiddleware already set Allow decision
        $exec->securityDecision = SecurityDecision::Allow;
        $exec->module = 'sample'; $exec->action = 'SecureNonSimple'; $exec->outputType = 'html';
        $req = $this->makeReq([ExecutionState::class=>$exec, ActionDescriptor::class=>$desc]);
        $resp = $mw->process($req, new class implements RequestHandlerInterface { public function handle(ServerRequestInterface $r): ResponseInterface { throw new RuntimeException('should not'); } });
        $this->assertSame(200,$resp->getStatusCode(), 'Forwarded pending validation non-simple path should execute instead of 500');
    }
}
