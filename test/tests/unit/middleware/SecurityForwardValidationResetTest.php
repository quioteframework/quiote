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
 * Ensures that a security forward (login/secure) resets validation decision and target descriptor
 * so that the forwarded action is validated and executed exactly once.
 */
final class SecurityForwardValidationResetTest extends AgaviUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Ensure minimal config baseline
        if(!\Agavi\Config\AgaviConfig::has('core.app_dir')) { \Agavi\Config\AgaviConfig::set('core.app_dir', getcwd()); }
        if(!\Agavi\Config\AgaviConfig::has('core.default_context')) { \Agavi\Config\AgaviConfig::set('core.default_context', 'web'); }
        // Configure action to require auth so security triggers forward.
        \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(false,true,false);
        $user = $this->getContext()->getUser();
        if(method_exists($user,'setAuthenticated')) { $user->setAuthenticated(false); }
        if(method_exists($user,'clearCredentials')) { try { $user->clearCredentials(); } catch(\Throwable) {} }
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

    public function testSecureForwardResetsValidation(): void
    {
        $controller = $this->getContext()->getController();
        $security = new SecurityMiddleware($controller);
        $validation = new ValidationMiddleware($controller);
        $dispatch = new DispatchMiddleware($controller);
        $state = new ExecutionState();
        $state->validationDecision = ValidationDecision::passed(); // simulate original action validated
        $psr = $this->buildPsr()->withAttribute(ExecutionState::class, $state);

        // Phase 1: run only security and capture immediate reset before validation executes.
        $phase1 = $security->process($psr, new class implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface {
                return (new Psr17Factory())->createResponse(202); // short‑circuit before validation
            }
        });
        $this->assertSame(202, $phase1->getStatusCode());
        $this->assertTrue($state->forwarded, 'Expected forwarded flag after security');
        $this->assertSame('pending', $state->validationDecision->state, 'Security forward should reset validation decision to pending');

        // Phase 2: now run validation + dispatch using the mutated state; it should move to passed.
        $resp = $validation->process($psr, new class($dispatch) implements \Psr\Http\Server\RequestHandlerInterface {
            public function __construct(private $dispatch){}
            public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface {
                return $this->dispatch->process($r, new class implements \Psr\Http\Server\RequestHandlerInterface { public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface { return (new Psr17Factory())->createResponse(200); } });
            }
        });
        $this->assertSame('passed', $state->validationDecision->state, 'Validation middleware should evaluate forwarded action');
        $this->assertSame(200, $resp->getStatusCode());
    }
}
