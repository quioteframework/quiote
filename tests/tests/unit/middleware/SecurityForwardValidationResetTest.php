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
 * Ensures that a security forward (login/secure) resets validation decision and target descriptor
 * so that the forwarded action is validated and executed exactly once.
 */
#[RunTestsInSeparateProcesses]
final class SecurityForwardValidationResetTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Ensure minimal config baseline
        if(!\Quiote\Config\Config::has('core.app_dir')) { \Quiote\Config\Config::set('core.app_dir', getcwd()); }
        if(!\Quiote\Config\Config::has('core.default_context')) { \Quiote\Config\Config::set('core.default_context', 'web'); }
        // Configure action to require auth so security triggers forward.
        \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(false,true,false);
        $user = $this->getContext()->getUser();
        if(method_exists($user,'setAuthenticated')) { $user->setAuthenticated(false); }
        if(method_exists($user,'clearCredentials')) { try { $user->clearCredentials(); } catch(\Throwable) {} }
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

    public function testSecureForwardResetsValidation(): void
    {
        $controller = $this->getContext()->getController();
        $security = new SecurityMiddleware($controller);
        $validation = new ValidationMiddleware($controller);
        $dispatch = new DispatchMiddleware($controller);
        $state = new ExecutionState();
        $state->validationDecision = ValidationDecision::passed(); // simulate original action validated
        $psr = $this->buildPsr()->withAttribute(ExecutionState::class, $state);

        // Run only security and capture immediate reset before validation executes.
        $securityOnlyResponse = $security->process($psr, new class implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface {
                return (new Psr17Factory())->createResponse(202); // short‑circuit before validation
            }
        });
        $this->assertSame(202, $securityOnlyResponse->getStatusCode());
        $this->assertTrue($state->forwarded, 'Expected forwarded flag after security');
        $this->assertSame('pending', $state->validationDecision->state, 'Security forward should reset validation decision to pending');

        // Now run validation + dispatch using the mutated state; it should move to passed.
        $resp = $validation->process($psr, new class($dispatch) implements \Psr\Http\Server\RequestHandlerInterface {
            public function __construct(private DispatchMiddleware $dispatch){}
            public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface {
                return $this->dispatch->process($r, new class implements \Psr\Http\Server\RequestHandlerInterface { public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface { return (new Psr17Factory())->createResponse(200); } });
            }
        });
        $this->assertSame('passed', $state->validationDecision->state, 'Validation middleware should evaluate forwarded action');
        $this->assertSame(200, $resp->getStatusCode());
    }
}
