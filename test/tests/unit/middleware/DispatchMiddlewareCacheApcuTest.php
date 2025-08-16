<?php

use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Config\AgaviConfig;
use Agavi\Cache\CacheManager;
use Agavi\Middleware\DispatchMiddleware;
use Agavi\Http\PsrServerRequestAdapter;
use Agavi\Execution\ActionDescriptor;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Stream;

class DispatchMiddlewareCacheApcuTest extends AgaviUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('apcu_enabled') || !apcu_enabled()) {
            $this->markTestSkipped('APCu not available');
        }
        if(!AgaviConfig::get('core.cache_enabled', false)) {
            $this->markTestSkipped('Global cache disabled via core.cache_enabled');
        }
        AgaviConfig::set('core.cache_backend', 'apcu');
        CacheManager::reset();
        $this->getContext()->getController()->initializeModule('Cache');
    }

    private function req(ActionDescriptor $descriptor) {
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
            ->withAttribute(ActionDescriptor::class, $descriptor)
            ->withAttribute('module', 'Cache')
            ->withAttribute('action', 'Cache');
    }

    public function testApcuBackendCaches()
    {
        $controller = $this->getContext()->getController();
        $controller->createActionInstance('Cache','Cache');
    $d1 = ActionDescriptor::fromController($controller,'Cache','Cache','GET', strtolower($controller->getOutputType()->getName()));
        $mw = new DispatchMiddleware($controller);
    \Sandbox\Modules\Cache\Actions\CacheAction::$execCount = 0;
    $mw->process($this->req($d1), new class(new Psr17Factory) implements \Psr\Http\Server\RequestHandlerInterface { public function __construct(private $f){} public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface { return $this->f->createResponse(200);} });
        $this->assertSame('apcu', CacheManager::getBackend());
    $this->assertSame(1, \Sandbox\Modules\Cache\Actions\CacheAction::$execCount);
        $controller->createActionInstance('Cache','Cache');
    $d2 = ActionDescriptor::fromController($controller,'Cache','Cache','GET', strtolower($controller->getOutputType()->getName()));
    $mw->process($this->req($d2), new class(new Psr17Factory) implements \Psr\Http\Server\RequestHandlerInterface { public function __construct(private $f){} public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface { return $this->f->createResponse(200);} });
    $this->assertSame(1, \Sandbox\Modules\Cache\Actions\CacheAction::$execCount, 'Second run should be cached');
    }
}
