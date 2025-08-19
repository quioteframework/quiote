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
        $mw = new DispatchMiddleware($controller);
        $handler = new class(new Psr17Factory) implements \Psr\Http\Server\RequestHandlerInterface { public function __construct(private $f){} public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface { return $this->f->createResponse(200);} };
        $resp = $mw->process($psr->withAttribute(ExecutionState::class,$state), $handler);
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
    // Simulate SecurityMiddleware short-circuit
    $state = new ExecutionState();
    $state->securityDecision = \Agavi\Execution\SecurityDecision::LoginForward;
    $state->forwarded = true;
    $forwardService = new \Agavi\Execution\ForwardService($this->getContext()->getController());
    [$view,$vm,$vn,$content] = $forwardService->createSystemForwardView('login', 'html', new \Agavi\Request\AgaviRequestDataHolder());
    $req = $this->buildPsr()->withAttribute('agavi.forward_view', [$view,$vm,$vn,$content]);
    $body = $this->runMw($req, $state);
    // ForwardService now returns empty tuple (no direct view rendering); ensure middleware short-circuited with empty body allowed.
    $this->assertSame('', $vn, 'Login forward now yields empty view name');
    $this->assertSame('', $body, 'Login forward produces empty content placeholder');
    }

    public function testSecurityCredentialForward()
    {
    \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(false,false,true); // require credential
        $user = $this->getContext()->getUser();
        if(method_exists($user,'removeCredential')) { $user->removeCredential('complex_cred'); }
    // Simulate SecurityMiddleware secure forward
    $state = new ExecutionState();
    $state->securityDecision = \Agavi\Execution\SecurityDecision::SecureForward;
    $state->forwarded = true;
    $forwardService = new \Agavi\Execution\ForwardService($this->getContext()->getController());
    [$view,$vm,$vn,$content] = $forwardService->createSystemForwardView('secure', 'html', new \Agavi\Request\AgaviRequestDataHolder());
    $req = $this->buildPsr()->withAttribute('agavi.forward_view', [$view,$vm,$vn,$content]);
    $body = $this->runMw($req, $state);
    $this->assertSame('', $vn, 'Secure forward now yields empty view name');
    $this->assertSame('', $body, 'Secure forward produces empty content placeholder');
    }
}
