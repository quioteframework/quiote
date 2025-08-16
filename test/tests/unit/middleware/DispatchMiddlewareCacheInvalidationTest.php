<?php

use Agavi\Testing\AgaviUnitTestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Agavi\Middleware\DispatchMiddleware;
use Agavi\Controller\AgaviController;
use Agavi\Http\PsrServerRequestAdapter;
use Agavi\Execution\ActionDescriptor;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Stream;
use Agavi\Config\AgaviConfig;
use Agavi\Cache\CacheManager;

#[RunTestsInSeparateProcesses]
class DispatchMiddlewareCacheInvalidationTest extends AgaviUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if(!AgaviConfig::get('core.cache_enabled', false)) {
            $this->markTestSkipped('Global cache disabled via core.cache_enabled');
        }
    AgaviConfig::set('core.cache_dir', sys_get_temp_dir() . '/agavi_cache_test');
    $dir = AgaviConfig::get('core.cache_dir'); if(!is_dir($dir)) { @mkdir($dir, 0775, true); }
        \Agavi\Cache\CacheManager::reset();
        $psrDir = AgaviConfig::get('core.cache_dir') . '/psr-cache';
        if (is_dir($psrDir)) {
            $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($psrDir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($rii as $file) { $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname()); }
            @rmdir($psrDir);
        }
        $this->getContext()->getController()->initializeModule('Cache');
    }

    private function newRequest(ActionDescriptor $descriptor) {
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

    public function testModuleInvalidationBumpsVersion()
    {
        $controller = $this->getContext()->getController();
        $controller->createActionInstance('Cache','Cache');
    $d1 = ActionDescriptor::fromController($controller,'Cache','Cache','GET', strtolower($controller->getOutputType()->getName()));
        $mw = new DispatchMiddleware($controller);
    \Sandbox\Modules\Cache\Actions\CacheAction::$execCount = 0;
    $factory = new Psr17Factory();
    $mw->process($this->newRequest($d1), new class($factory) implements \Psr\Http\Server\RequestHandlerInterface { public function __construct(private $f){} public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface { return $this->f->createResponse(200);} });
    if (\Sandbox\Modules\Cache\Actions\CacheAction::$execCount === 0) {
            fwrite(STDERR, "DEBUG: execCount after first run is 0\n");
        }
    $this->assertSame(1, \Sandbox\Modules\Cache\Actions\CacheAction::$execCount);
        // second run should hit cache
        $controller->createActionInstance('Cache','Cache');
        $d2 = ActionDescriptor::fromController($controller,'Cache','Cache','GET', strtolower($controller->getOutputType()->getName()));
    $mw->process($this->newRequest($d2), new class($factory) implements \Psr\Http\Server\RequestHandlerInterface { public function __construct(private $f){} public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface { return $this->f->createResponse(200);} });
    $this->assertSame(1, \Sandbox\Modules\Cache\Actions\CacheAction::$execCount);
        // invalidate module
        CacheManager::invalidateModule('Cache');
        // third run should re-execute
        $controller->createActionInstance('Cache','Cache');
        $d3 = ActionDescriptor::fromController($controller,'Cache','Cache','GET', strtolower($controller->getOutputType()->getName()));
    $mw->process($this->newRequest($d3), new class($factory) implements \Psr\Http\Server\RequestHandlerInterface { public function __construct(private $f){} public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface { return $this->f->createResponse(200);} });
    $this->assertSame(2, \Sandbox\Modules\Cache\Actions\CacheAction::$execCount, 'Exec count should increase after invalidation');
    }
}
