<?php

require_once __DIR__ . '/CacheMiddlewareTestTrait.php';

use Agavi\Testing\AgaviUnitTestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Agavi\Cache\CacheManager;
use Agavi\Middleware\DispatchMiddleware;
use Agavi\Execution\ActionDescriptor;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;

#[RunTestsInSeparateProcesses]
class DispatchMiddlewareCacheInvalidationTest extends AgaviUnitTestCase
{
    use CacheMiddlewareTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapCache('agavi_cache_invalidation');
        $this->getContext()->getController()->initializeModule('Cache');
    }

    protected function tearDown(): void
    {
        $this->restoreCache();
        parent::tearDown();
    }

    private function newRequest(ActionDescriptor $descriptor) {
        $factory = new Psr17Factory();
        $psr = (new ServerRequest('GET', 'http://localhost/cache'))
            ->withBody($factory->createStream(''));
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
