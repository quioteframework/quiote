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

/**
 * Ensures non-simple actions served from cache in no-container mode use PSR replay (no container).
 */
class DispatchMiddlewareNoContainerNonSimpleCacheTest extends AgaviUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if(!AgaviConfig::get('core.cache_enabled', false)) {
            $this->markTestSkipped('Global cache disabled via core.cache_enabled');
        }
        AgaviConfig::set('core.cache_dir', sys_get_temp_dir() . '/agavi_ctx_nonsimple_nocontainer_cache_test');
        $dir = AgaviConfig::get('core.cache_dir');
        if(!is_dir($dir)) { @mkdir($dir, 0777, true); }
        CacheManager::reset();
        putenv('AGAVI_DISPATCH_CONTEXT=1');
        putenv('AGAVI_DISPATCH_CONTEXT_NONSIMPLE=1');
        putenv('AGAVI_DISPATCH_CONTEXT_NONSIMPLE_NOCONTAINER=1');
        // Preload action class
        $this->getContext()->getController()->createActionInstance('Cache','CacheComplex');
        // user baseline authenticated + credential to avoid forwards
        $user = $this->getContext()->getUser();
        if(method_exists($user,'setAuthenticated')) { $user->setAuthenticated(true); }
        if(method_exists($user,'addCredential')) { $user->addCredential('complex_cred'); }
        \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(false,false,false); // success path
    }

    protected function tearDown(): void
    {
        putenv('AGAVI_DISPATCH_CONTEXT_NONSIMPLE_NOCONTAINER');
        putenv('AGAVI_DISPATCH_CONTEXT_NONSIMPLE');
        parent::tearDown();
    }

    private function buildPsr(): \Psr\Http\Message\ServerRequestInterface
    {
        $factory = new Psr17Factory();
        $legacyReq = $this->getContext()->getRequest();
        $psr = new PsrServerRequestAdapter(
            $legacyReq,
            $factory->createUri('http://localhost/cache/complex'),
            'GET',
            Stream::create(''),
            [], [], [], [], [], []
        );
        return $psr
            ->withAttribute('module','Cache')
            ->withAttribute('action','CacheComplex')
            ->withAttribute('output_type','html')
            ->withAttribute(ActionDescriptor::class, ActionDescriptor::fromController($this->getContext()->getController(),'Cache','CacheComplex','GET','html'));
    }

    private function runMw(\Psr\Http\Message\ServerRequestInterface $psr, ExecutionState $state): \Psr\Http\Message\ResponseInterface
    {
        $controller = $this->getContext()->getController();
        $mw = new DispatchMiddleware($controller);
        $handler = new class(new Psr17Factory) implements \Psr\Http\Server\RequestHandlerInterface { public function __construct(private $f){} public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface { return $this->f->createResponse(200);} };
        return $mw->process($psr->withAttribute(ExecutionState::class,$state), $handler);
    }

    public function testCacheHitSecondRequestNoContainer()
    {
        $state1 = new ExecutionState();
        $resp1 = $this->runMw($this->buildPsr(), $state1);
        $this->assertStringContainsString('COMPLEX_OK', (string)$resp1->getBody());
        $this->assertFalse($state1->cacheHit, 'First run should not be a cache hit');
        $this->assertSame('0', $resp1->getHeaderLine('X-Agavi-Container-Used'), 'No container expected on first execution');

        $state2 = new ExecutionState();
        $resp2 = $this->runMw($this->buildPsr(), $state2);
        $this->assertStringContainsString('COMPLEX_OK', (string)$resp2->getBody());
        $this->assertTrue($state2->cacheHit, 'Second run should be served from cache');
        $this->assertSame('1', $resp2->getHeaderLine('X-Agavi-Cache-Hit'));
        $this->assertSame('0', $resp2->getHeaderLine('X-Agavi-Container-Used'), 'Cache replay should remain container-less');
    }
}
