<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Execution\ActionDescriptor;
use Quiote\Execution\ExecutionState;
use Quiote\Execution\ValidationDecision;
use Quiote\Middleware\SecurityMiddleware;
use Quiote\Middleware\ValidationMiddleware;
use Quiote\Middleware\DispatchMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Verifies forwardCount limit (508) is enforced after >5 forwards to prevent loops.
 * We simulate by feeding the middleware a descriptor that always triggers secure/login forward by
 * configuring the action to require auth while user stays unauthenticated.
 */
#[RunTestsInSeparateProcesses]
final class SecurityForwardLoopLimitTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if(!\Quiote\Config\Config::has('core.app_dir')) { \Quiote\Config\Config::set('core.app_dir', getcwd()); }
        if(!\Quiote\Config\Config::has('core.default_context')) { \Quiote\Config\Config::set('core.default_context', 'web'); }
        \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(false,true,false);
        $u = $this->getContext()->getUser();
        if(method_exists($u,'setAuthenticated')) { $u->setAuthenticated(false); }
        // Ensure context has an WebRequest (required by ValidationMiddleware)
        $this->getContext()->getRequest();
    }

    private function buildPsr(): \Psr\Http\Message\ServerRequestInterface
    {
        $controller = $this->getContext()->getController();
        $descriptor = ActionDescriptor::fromController($controller,'Cache','CacheComplex','GET','html');
        return (new ServerRequest('GET', 'http://localhost/cache/complex'))
            ->withAttribute(ActionDescriptor::class, $descriptor)
            ->withAttribute('module','Cache')
            ->withAttribute('action','CacheComplex')
            ->withAttribute('output_type','html');
    }

    public function testForwardLimitProduces508(): void
    {
        $controller = $this->getContext()->getController();
        $security = new SecurityMiddleware($controller);
        $validation = new ValidationMiddleware($controller);
        $dispatch = new DispatchMiddleware($controller);
        $state = new ExecutionState();
        $state->validationDecision = ValidationDecision::passed();
        $psr = $this->buildPsr()->withAttribute(ExecutionState::class, $state);
        $resp = null;
        for($i=0;$i<7;$i++) {
            $resp = $security->process($psr, new class($validation,$dispatch) implements \Psr\Http\Server\RequestHandlerInterface { public function __construct(private ValidationMiddleware $validation, private DispatchMiddleware $dispatch){} public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface { return $this->validation->process($r, new class($this->dispatch) implements \Psr\Http\Server\RequestHandlerInterface { public function __construct(private DispatchMiddleware $dispatch){} public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface { return $this->dispatch->process($r, new class implements \Psr\Http\Server\RequestHandlerInterface { public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface { return (new Psr17Factory())->createResponse(200); } }); } }); } });
            if($resp->getStatusCode() === 508) { break; }
            // Reattach mutated execution state for next pass
            $psr = $psr->withAttribute(ExecutionState::class, $state);
        }
        $this->assertSame(508, $resp->getStatusCode(), 'Expected 508 after exceeding forward limit');
        $this->assertGreaterThan(5, $state->forwardCount);
    }
}
