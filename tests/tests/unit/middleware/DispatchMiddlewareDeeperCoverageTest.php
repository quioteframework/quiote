<?php
use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Context;
use Quiote\Middleware\DispatchMiddleware;
use Quiote\Execution\ActionDescriptor;
use Quiote\Execution\ExecutionState;
use Quiote\Execution\SecurityDecision;
use Quiote\Execution\ValidationDecision;

/**
 * Deeper coverage for DispatchMiddleware: secure simple heuristic and forwarded pending validation path.
 *
 * Run in a separate process: setUp() sets core.environment to 'development'
 * via a plain Config::set() (readonly defaults to false), but the first
 * Context::getInstance()/Quiote::bootstrap() call anywhere in the process
 * afterward locks core.environment read-only at whatever value it currently
 * holds. Left in the shared test process, that permanently pins
 * core.environment to 'development' for every later test file -- including
 * ones (e.g. RoutesListCommandTest) that depend on it matching factories.xml's
 * `environment="testing.*"` blocks. #[RunTestsInSeparateProcesses] contains
 * that lock to this class's own process, mirroring MiddlewareAttributeOrderingTest.
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class DispatchMiddlewareDeeperCoverageTest extends TestCase
{
    private function ctx(): Context { return Context::getInstance(); }
    /**
     * @param array<string, mixed> $attrs
     */
    private function makeReq(array $attrs = []): ServerRequestInterface { $r = new ServerRequest('GET', 'http://localhost/deeper'); foreach($attrs as $k=>$v){ $r = $r->withAttribute($k,$v);} return $r; }

    public function setUp(): void
    {
        \Quiote\Config\Config::set('core.environment','development');
        // ensure security enabled
        \Quiote\Config\Config::set('core.use_security', true);
    }

    public function testSecureSimpleActionHeuristicSetsSecurityDecisionAllow(): void
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

    public function testForwardedPendingValidationSkips500(): void
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
