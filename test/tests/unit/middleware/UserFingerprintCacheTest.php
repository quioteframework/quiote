<?php

use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Config\AgaviConfig;
use Agavi\Cache\CacheManager;
use Agavi\Middleware\DispatchMiddleware;
use Agavi\Execution\ExecutionState;
use Agavi\Execution\ActionDescriptor;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Stream;
use Agavi\Http\PsrServerRequestAdapter;

class UserFingerprintCacheTest extends AgaviUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Minimal required core paths for bootstrap if not already defined
        if(!AgaviConfig::has('core.app_dir')) {
            AgaviConfig::set('core.app_dir', realpath(__DIR__ . '/../../../sandbox/app'));
        }
        if(!AgaviConfig::has('core.config_dir')) {
            // Correct case: Config (not config)
            AgaviConfig::set('core.config_dir', realpath(__DIR__ . '/../../../sandbox/app/Config'));
        }
        AgaviConfig::set('core.cache_dir', sys_get_temp_dir() . '/agavi_fp_cache_test');
        $dir = AgaviConfig::get('core.cache_dir');
        if(!is_dir($dir)) { @mkdir($dir, 0777, true); }
        CacheManager::reset();
        putenv('AGAVI_DISPATCH_CONTEXT=1');
        putenv('AGAVI_DISPATCH_CONTEXT_SIMPLE=1');
    putenv('AGAVI_DISPATCH_CONTEXT_SIMPLE_NOCONTAINER=1');
    putenv('AGAVI_SECURITY_DEBUG=1');
    AgaviConfig::set('core.use_security', true); // ensure explicit; can flip to false for debugging
        // ensure action class loaded
        $this->getContext()->getController()->createActionInstance('Fingerprint','FingerprintSecure');
    // No auth set here; set explicitly per dispatch to verify persistence
    }

    protected function tearDown(): void
    {
        putenv('AGAVI_DISPATCH_CONTEXT_SIMPLE');
        parent::tearDown();
    }

    private function buildPsr(): \Psr\Http\Message\ServerRequestInterface
    {
        $factory = new Psr17Factory();
        $legacyReq = $this->getContext()->getRequest();
        $psr = new PsrServerRequestAdapter(
            $legacyReq,
            $factory->createUri('http://localhost/fingerprint/secure'),
            'GET',
            Stream::create(''),
            [], [], [], [], [], []
        );
        $desc = ActionDescriptor::fromController($this->getContext()->getController(),'Fingerprint','FingerprintSecure','GET','html');
        @file_put_contents('/tmp/agavi_security_debug.log', '[Test] descriptor isSimple=' . (int)$desc->isSimple . "\n", FILE_APPEND);
        return $psr
            ->withAttribute('module','Fingerprint')
            ->withAttribute('action','FingerprintSecure')
            ->withAttribute('output_type','html')
            ->withAttribute(ActionDescriptor::class, $desc);
    }

    private function runMw(\Psr\Http\Message\ServerRequestInterface $psr, ExecutionState $state): string
    {
        $controller = $this->getContext()->getController();
        $psr = $psr->withAttribute(ExecutionState::class, $state);
        $finalHandler = new class(new Psr17Factory) implements \Psr\Http\Server\RequestHandlerInterface {
            public function __construct(private $f){}
            public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface { return $this->f->createResponse(200); }
        };
        $dispatch = new DispatchMiddleware($controller);
        $security = new \Agavi\Middleware\SecurityMiddleware($controller);
        $resp = $security->process($psr, new class($dispatch, $finalHandler) implements \Psr\Http\Server\RequestHandlerInterface {
            public function __construct(private $dispatch, private $final) {}
            public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface { return $this->dispatch->process($r, $this->final); }
        });
        return (string)$resp->getBody();
    }

    public function testCacheSegregatedByAuthState()
    {
        if(!AgaviConfig::get('core.cache_enabled', false)) {
            $this->markTestSkipped('Global cache disabled via core.cache_enabled');
        }
        // First request as authenticated -> cache store
        $state1 = new ExecutionState();
        // Explicitly set user auth state instead of __auth attribute override.
        $this->getContext()->getUser()->setAuthenticated(true);
    $body1 = $this->runMw($this->buildPsr(), $state1);
        // Debug fast-fail if login forward
        if(str_contains($body1, 'LOGIN_REQUIRED')) {
            $this->fail('Security still thinks unauthenticated in first request');
        }
    if($body1 === '') { @file_put_contents('/tmp/agavi_user_debug.log', '[test] empty body first request\n', FILE_APPEND); }
    // Simple action returns view name 'Success' which resolves to FingerprintSecureSuccessView whose HTML contains SecureContentView
    $this->assertStringContainsString('SecureContentView', $body1);
        $this->assertFalse($state1->cacheHit, 'First should be miss');

        // Second request same user -> cache hit expected
        $state2 = new ExecutionState();
        $this->getContext()->getUser()->setAuthenticated(true); // ensure still authenticated
    $body2 = $this->runMw($this->buildPsr(), $state2);
        $this->assertTrue($state2->cacheHit, 'Second should be hit for same fingerprint');

        // Flip auth state -> fingerprint changes -> miss again
        $state3 = new ExecutionState();
        $this->getContext()->getUser()->setAuthenticated(false); // flip auth state
    $body3 = $this->runMw($this->buildPsr(), $state3);
        $this->assertFalse($state3->cacheHit, 'Auth state change should cause cache miss');
    $this->assertStringContainsString('SecureContentView', $body3);
    }
}
