<?php

use Agavi\Testing\AgaviUnitTestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Agavi\Middleware\DispatchMiddleware;
use Agavi\Controller\AgaviController;
use Agavi\AgaviContext;
use Agavi\Config\AgaviConfig;
use Agavi\Http\PsrServerRequestAdapter;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Stream;
use Agavi\Request\AgaviRequest;

#[RunTestsInSeparateProcesses]
class DispatchMiddlewareCacheTest extends AgaviUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Skip if global cache master switch is disabled (default off while refactoring)
        if(!AgaviConfig::get('core.cache_enabled', false)) {
            $this->markTestSkipped('Global cache disabled via core.cache_enabled');
        }
        // Isolated cache dir for content cache writes
    AgaviConfig::set('core.cache_dir', sys_get_temp_dir() . '/agavi_cache_test');
    $dir = AgaviConfig::get('core.cache_dir'); if(!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $this->getContext()->getController()->initializeModule('Cache');
    }

    public function testActionResponseIsCachedOnSecondRun()
    {
    $controller = $this->getContext()->getController();
    $req = $this->getContext()->getRequest();
        if (method_exists($req, 'startup')) { try { $req->startup(); } catch (\Throwable) {} }
        if (method_exists($controller, 'startup')) { $controller->startup(); }
        // Ensure PSR cache directory clean
        $psrDir = AgaviConfig::get('core.cache_dir') . DIRECTORY_SEPARATOR . 'psr-cache';
        if (is_dir($psrDir)) {
            $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($psrDir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($rii as $file) { $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname()); }
            @rmdir($psrDir);
        }
    // Ensure action class is loaded (namespaced CacheAction)
    $controller->createActionInstance('Cache','Cache');
    // Build ActionDescriptor (no legacy container)
    $descriptor = \Agavi\Execution\ActionDescriptor::fromController($controller, 'Cache','Cache','GET', strtolower($controller->getOutputType()->getName()));
        $factory = new Psr17Factory();
    $legacyReq = $this->getContext()->getRequest();
        $this->assertInstanceOf(\Agavi\Request\AgaviRequest::class, $legacyReq);
        $psr = new PsrServerRequestAdapter(
            $legacyReq,
            $factory->createUri('http://localhost/cache'),
            'GET',
            Stream::create(''),
            [], [], [], [], [], []
        );
        $psr = $psr
            ->withAttribute(\Agavi\Execution\ActionDescriptor::class, $descriptor)
            ->withAttribute('module', 'Cache')
            ->withAttribute('action', 'Cache');
        $mw = new DispatchMiddleware($controller);
    \Sandbox\Modules\Cache\Actions\CacheAction::$execCount = 0;
        $mw->process($psr, new class($factory) implements \Psr\Http\Server\RequestHandlerInterface { public function __construct(private $f){} public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface { return $this->f->createResponse(200); }});
    $this->assertSame(1, \Sandbox\Modules\Cache\Actions\CacheAction::$execCount, 'Action should execute once on first run');
        // Second run new container
    $controller->createActionInstance('Cache','Cache');
    $descriptor2 = \Agavi\Execution\ActionDescriptor::fromController($controller, 'Cache','Cache','GET', strtolower($controller->getOutputType()->getName()));
        $psr2 = $psr->withAttribute(\Agavi\Execution\ActionDescriptor::class, $descriptor2)
            ->withAttribute('module', 'Cache')
            ->withAttribute('action', 'Cache');
        $mw->process($psr2, new class($factory) implements \Psr\Http\Server\RequestHandlerInterface { public function __construct(private $f){} public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface { return $this->f->createResponse(200); }});
    $this->assertSame(1, \Sandbox\Modules\Cache\Actions\CacheAction::$execCount, 'Action should not execute again if cached');
    }
}
