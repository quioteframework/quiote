<?php

use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Config\AgaviConfig;
use Agavi\Cache\CacheManager;
use Agavi\Cache\ActionViewCache;
use Agavi\Middleware\DispatchMiddleware;
use Agavi\Http\PsrServerRequestAdapter;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Stream;
use Agavi\Execution\ExecutionState;
use Agavi\Execution\ActionDescriptor;

/**
 * Verifies that when a stale cached payload exists (from a prior test run) but the action's execCount static counter
 * has been reset to 0, the first request will bypass the stale cache and execute the action once, then cache fresh.
 */
class DispatchMiddlewareContextSimpleStaleCacheInvalidationTest extends AgaviUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if(!AgaviConfig::get('core.cache_enabled', false)) {
            $this->markTestSkipped('Global cache disabled via core.cache_enabled');
        }
        AgaviConfig::set('core.cache_dir', sys_get_temp_dir() . '/agavi_ctx_simple_cache_cache_test');
        $dir = AgaviConfig::get('core.cache_dir');
        if(!is_dir($dir)) { @mkdir($dir, 0777, true); }
        CacheManager::reset();
        putenv('AGAVI_DISPATCH_CONTEXT=1');
        putenv('AGAVI_DISPATCH_CONTEXT_SIMPLE=1');
        $this->getContext()->getController()->initializeModule('Cache');
        // Simulate a stale cache entry created by an earlier (different) process.
        $avCache = new ActionViewCache(CacheManager::getCache());
        $avCache->set('Cache','Cache','html',[
            'view_module' => 'Cache',
            'view_name' => 'Success',
            'action_attributes' => ['foo' => 'bar_stale'],
            'response_content' => 'STALE',
            'descriptor' => [
                'module' => 'Cache',
                'action' => 'Cache',
                'method' => 'execute',
                'outputType' => 'html',
                'isSimple' => true,
            ],
            'state' => [
                'validationPerformed' => false,
                'validationSucceeded' => true,
                'viewModule' => 'Cache',
                'viewName' => 'Success',
            ],
        ], null);
        // Reset execCount to 0 (mimicking test suite fresh expectation)
        \Sandbox\Modules\Cache\Actions\CacheAction::$execCount = 0;
    }

    private function buildPsr(): \Psr\Http\Message\ServerRequestInterface
    {
        $factory = new Psr17Factory();
        $legacyReq = $this->getContext()->getRequest();
        $psr = new PsrServerRequestAdapter(
            $legacyReq,
            $factory->createUri('http://localhost/cache'),
            'GET',
            Stream::create(''),
            [], [], [], [], [], []
        );
        return $psr
            ->withAttribute('module','Cache')
            ->withAttribute('action','Cache')
            ->withAttribute('output_type','html')
            ->withAttribute(ActionDescriptor::class, ActionDescriptor::fromController($this->getContext()->getController(),'Cache','Cache','GET','html'));
    }

    private function runMw(\Psr\Http\Message\ServerRequestInterface $psr, ExecutionState $state)
    {
        $controller = $this->getContext()->getController();
        $mw = new DispatchMiddleware($controller);
        $handler = new class(new Psr17Factory) implements \Psr\Http\Server\RequestHandlerInterface { public function __construct(private $f){} public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface { return $this->f->createResponse(200);} };
        return $mw->process($psr->withAttribute(ExecutionState::class,$state), $handler);
    }

    public function testStaleCacheBypassedOnFirstRequestThenReused()
    {
        // First request should IGNORE stale cache (execCount increments, no cache-hit header)
        $state1 = new ExecutionState();
        $resp1 = $this->runMw($this->buildPsr(), $state1);
        $body1 = (string)$resp1->getBody();
        $this->assertStringContainsString('CACHE_HTML', $body1, 'Fresh render should include canonical marker');
        $this->assertSame(1, \Sandbox\Modules\Cache\Actions\CacheAction::$execCount, 'execCount must increment on first request ignoring stale cache');
        $this->assertEmpty($resp1->getHeader('X-Agavi-Cache-Hit'), 'First request should not signal cache hit');

        // Second request should now be a cache hit (using refreshed payload)
        $state2 = new ExecutionState();
        $resp2 = $this->runMw($this->buildPsr(), $state2);
        $body2 = (string)$resp2->getBody();
        $this->assertStringContainsString('CACHE_HTML', $body2);
        $this->assertSame(1, \Sandbox\Modules\Cache\Actions\CacheAction::$execCount, 'Second request served from cache');
        $this->assertSame(['1'], $resp2->getHeader('X-Agavi-Cache-Hit'));
    }
}
