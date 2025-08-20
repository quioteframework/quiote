<?php

use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Config\AgaviConfig;
use Agavi\Cache\CacheManager;
use Agavi\Middleware\DispatchMiddleware;
use Agavi\Http\PsrServerRequestAdapter;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Stream;
use Agavi\Execution\ExecutionState;
use Agavi\Execution\ActionDescriptor;
use Agavi\View\AgaviView;

/**
 * Tests container-less non-simple action execution (validation + security) via DispatchMiddleware.
 */
class DispatchMiddlewareContextNonSimpleTest extends AgaviUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    // Ensure minimal required configuration directives for context access
    if(!AgaviConfig::has('core.app_dir')) { AgaviConfig::set('core.app_dir', getcwd()); }
    if(!AgaviConfig::has('core.default_context')) { AgaviConfig::set('core.default_context', 'web'); }
        AgaviConfig::set('core.cache_dir', sys_get_temp_dir() . '/agavi_ctx_nonsimple_cache_test');
        $dir = AgaviConfig::get('core.cache_dir');
        if(!is_dir($dir)) { @mkdir($dir, 0777, true); }
        CacheManager::reset();
        putenv('AGAVI_DISPATCH_CONTEXT=1');
        putenv('AGAVI_DISPATCH_CONTEXT_NONSIMPLE=1');
        // Preload module + action class
    $this->getContext()->getController()->createActionInstance('Cache','CacheComplex'); // namespaced Sandbox\Modules\Cache\Actions\CacheComplexAction
        // Ensure user baseline state
        $user = $this->getContext()->getUser();
        if(method_exists($user,'setAuthenticated')) { $user->setAuthenticated(true); }
        if(method_exists($user,'addCredential')) { $user->addCredential('complex_cred'); }
    }

    protected function tearDown(): void
    {
        putenv('AGAVI_DISPATCH_CONTEXT_NONSIMPLE');
        parent::tearDown();
    }

    private function buildPsr(array $query = []): \Psr\Http\Message\ServerRequestInterface
    {
        $factory = new Psr17Factory();
        $legacyReq = $this->getContext()->getRequest();
        $psr = new PsrServerRequestAdapter(
            $legacyReq,
            $factory->createUri('http://localhost/cache/complex'),
            'GET',
            Stream::create(''),
            [], [], [], $query, [], []
        );
        return $psr
            ->withAttribute('module','Cache')
            ->withAttribute('action','CacheComplex')
            ->withAttribute('output_type','html')
            ->withAttribute(ActionDescriptor::class, ActionDescriptor::fromController($this->getContext()->getController(),'Cache','CacheComplex','GET','html'));
    }

    private function runMw(\Psr\Http\Message\ServerRequestInterface $psr, ExecutionState $state): string
    {
        $controller = $this->getContext()->getController();
        $security = new \Agavi\Middleware\SecurityMiddleware($controller);
        $dispatch = new DispatchMiddleware($controller);
        $handler = new class($dispatch) implements \Psr\Http\Server\RequestHandlerInterface {
            public function __construct(private DispatchMiddleware $dispatch) {}
            public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface { return $this->dispatch->process($r, new class implements \Psr\Http\Server\RequestHandlerInterface { public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface { return (new Psr17Factory())->createResponse(500); } }); }
        };
        // First run security, then dispatch
    // Simulate that ValidationMiddleware executed by setting attribute marker
    $psr = $psr->withAttribute('agavi.validation.ran', true);
    $resp = $security->process($psr->withAttribute(ExecutionState::class,$state), $handler);
        return (string)$resp->getBody();
    }

    public function testNonSimpleActionSuccess()
    {
    \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(false,false,false);
    // Simulate prior ValidationMiddleware success
    $state = new ExecutionState();
    $state->validationDecision = \Agavi\Execution\ValidationDecision::passed();
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
    $state->validationDecision = \Agavi\Execution\ValidationDecision::failed(['forced']);
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
    $state->validationDecision = \Agavi\Execution\ValidationDecision::passed();
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
    $state->validationDecision = \Agavi\Execution\ValidationDecision::passed();
        $body = $this->runMw($this->buildPsr(), $state);
        $this->assertStringContainsString('SECURE_REQUIRED', $body, 'Secure forward should render secure required content');
        $this->assertTrue($state->forwarded, 'ExecutionState should be marked forwarded');
    }
}
