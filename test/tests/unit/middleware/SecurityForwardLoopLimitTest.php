<?php

use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Execution\ActionDescriptor;
use Agavi\Execution\ExecutionState;
use Agavi\Execution\ValidationDecision;
use Agavi\Middleware\SecurityMiddleware;
use Agavi\Middleware\ValidationMiddleware;
use Agavi\Middleware\DispatchMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;

/**
 * Verifies forwardCount limit (508) is enforced after >5 forwards to prevent loops.
 * We simulate by feeding the middleware a descriptor that always triggers secure/login forward by
 * configuring the action to require auth while user stays unauthenticated.
 */
final class SecurityForwardLoopLimitTest extends AgaviUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if(!\Agavi\Config\AgaviConfig::has('core.app_dir')) { \Agavi\Config\AgaviConfig::set('core.app_dir', getcwd()); }
        if(!\Agavi\Config\AgaviConfig::has('core.default_context')) { \Agavi\Config\AgaviConfig::set('core.default_context', 'web'); }
        \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(false,true,false);
        $u = $this->getContext()->getUser();
        if(method_exists($u,'setAuthenticated')) { $u->setAuthenticated(false); }
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
            $resp = $security->process($psr, new class($validation,$dispatch) implements \Psr\Http\Server\RequestHandlerInterface { public function __construct(private $validation, private $dispatch){} public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface { return $this->validation->process($r, new class($this->dispatch) implements \Psr\Http\Server\RequestHandlerInterface { public function __construct(private $dispatch){} public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface { return $this->dispatch->process($r, new class implements \Psr\Http\Server\RequestHandlerInterface { public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface { return (new Psr17Factory())->createResponse(200); } }); } }); } });
            if($resp->getStatusCode() === 508) { break; }
            // Reattach mutated execution state for next pass
            $psr = $psr->withAttribute(ExecutionState::class, $state);
        }
        $this->assertSame(508, $resp->getStatusCode(), 'Expected 508 after exceeding forward limit');
        $this->assertGreaterThan(5, $state->forwardCount);
    }
}
