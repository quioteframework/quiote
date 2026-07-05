<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Config\Config;
use Quiote\Cache\CacheManager;
use Quiote\Middleware\DispatchMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Quiote\Execution\ExecutionState;
use Quiote\Execution\ActionDescriptor;
use Quiote\View\View;

/**
 * Tests container-less non-simple action execution (validation + security) via DispatchMiddleware.
 */
class DispatchMiddlewareContextNonSimpleTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    // Ensure minimal required configuration directives for context access
    if(!Config::has('core.app_dir')) { Config::set('core.app_dir', getcwd()); }
    if(!Config::has('core.default_context')) { Config::set('core.default_context', 'web'); }
        Config::set('core.cache_dir', sys_get_temp_dir() . '/quiote_ctx_nonsimple_cache_test');
        $dir = Config::getString('core.cache_dir');
        if(!is_dir($dir)) { @mkdir($dir, 0777, true); }
        // These tests exercise the plain (uncached) dispatch path. Other tests
        // (e.g. SlotCacheTest, DispatchMiddlewareExecutionStateTest) enable the
        // action/view cache via these process-wide directives and may not restore
        // them; if they leak in, the forwarded system action replays an empty
        // cached payload and the body comes back blank. Force caching off here so
        // the test is deterministic regardless of execution order.
        Config::set('core.cache_enabled', false);
        Config::set('core.use_cache', false);
        CacheManager::reset();
        putenv('QUIOTE_DISPATCH_CONTEXT=1');
        putenv('QUIOTE_DISPATCH_CONTEXT_NONSIMPLE=1');
        // Preload module + action class
    $this->getContext()->getController()->createActionInstance('Cache','CacheComplex'); // namespaced Sandbox\Modules\Cache\Actions\CacheComplexAction
        // Ensure user baseline state
        $user = $this->getContext()->getUser();
        if(method_exists($user,'setAuthenticated')) { $user->setAuthenticated(true); }
        if(method_exists($user,'addCredential')) { $user->addCredential('complex_cred'); }
    }

    protected function tearDown(): void
    {
        putenv('QUIOTE_DISPATCH_CONTEXT_NONSIMPLE');
        parent::tearDown();
    }

    private function buildPsr(array $query = []): \Psr\Http\Message\ServerRequestInterface
    {
        $controller = $this->getContext()->getController();
        $descriptor = ActionDescriptor::fromController($controller,'Cache','CacheComplex','GET','html');
        $req = (new ServerRequest('GET', 'http://localhost/cache/complex'))->withQueryParams($query);
        return $req
            ->withAttribute('module','Cache')
            ->withAttribute('action','CacheComplex')
            ->withAttribute('output_type','html')
            ->withAttribute(ActionDescriptor::class, $descriptor);
    }

    private function runMw(\Psr\Http\Message\ServerRequestInterface $psr, ExecutionState $state): string
    {
        $controller = $this->getContext()->getController();
        $security = new \Quiote\Middleware\SecurityMiddleware($controller);
        $dispatch = new DispatchMiddleware($controller);
        $handler = new readonly class($dispatch) implements \Psr\Http\Server\RequestHandlerInterface {
            public function __construct(private DispatchMiddleware $dispatch) {}
            public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface { return $this->dispatch->process($r, new class implements \Psr\Http\Server\RequestHandlerInterface { public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface { return (new Psr17Factory())->createResponse(500); } }); }
        };
        // First run security, then dispatch
    // Simulate that ValidationMiddleware executed by setting attribute marker
    $psr = $psr->withAttribute('quiote.validation.ran', true);
    $resp = $security->process($psr->withAttribute(ExecutionState::class,$state), $handler);
        return (string)$resp->getBody();
    }

    public function testNonSimpleActionSuccess()
    {
    \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(false,false,false);
    // Simulate prior ValidationMiddleware success
    $state = new ExecutionState();
    $state->validationDecision = \Quiote\Execution\ValidationDecision::passed();
        $body = $this->runMw($this->buildPsr(), $state);
        $this->assertStringContainsString('COMPLEX_OK', $body, 'Expected success view content');
    $this->assertSame('CacheComplexSuccess', $state->viewName);
    $this->assertTrue($state->validationDecision->isPassed(), 'Validation should succeed');
    $this->assertNotNull($state->validationDecision);
    $this->assertTrue($state->validationDecision->isPassed());
    }

    public function testValidationFailureTriggersErrorView()
    {
    \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(true,false,false);
    // Simulate prior ValidationMiddleware failure (DispatchMiddleware should not be invoked in real flow)
    $state = new ExecutionState();
    $state->validationDecision = \Quiote\Execution\ValidationDecision::failed(['forced']);
    // Expect DispatchMiddleware to block execution due to failed validation
    $body = $this->runMw($this->buildPsr(), $state);
    $this->assertStringContainsString('Validation Failed', $body, 'Expected validation failure short-circuit');
    $this->assertTrue($state->validationDecision->isFailed());
    $this->assertNotNull($state->validationDecision);
    $this->assertTrue($state->validationDecision->isFailed());
    }

    public function testSecurityLoginForward()
    {
        \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(false,true,false); // require auth
        $user = $this->getContext()->getUser();
        if(method_exists($user,'setAuthenticated')) { $user->setAuthenticated(false); }
    $state = new ExecutionState();
    // Simulate successful validation before security forward decision
    $state->validationDecision = \Quiote\Execution\ValidationDecision::passed();
        $body = $this->runMw($this->buildPsr(), $state);
        $this->assertStringContainsString('LOGIN_REQUIRED', $body, 'Login forward should render login required content');
        $this->assertTrue($state->forwarded, 'ExecutionState should be marked forwarded');
    }

    public function testSecurityCredentialForward()
    {
        \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(false,false,true); // require credential
        $user = $this->getContext()->getUser();
        if(method_exists($user,'removeCredential')) { $user->removeCredential('complex_cred'); }
    $state = new ExecutionState();
    $state->validationDecision = \Quiote\Execution\ValidationDecision::passed();
        $body = $this->runMw($this->buildPsr(), $state);
        $this->assertStringContainsString('SECURE_REQUIRED', $body, 'Secure forward should render secure required content');
        $this->assertTrue($state->forwarded, 'ExecutionState should be marked forwarded');
    }
}
