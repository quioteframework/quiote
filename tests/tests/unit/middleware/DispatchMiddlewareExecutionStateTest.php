<?php

use Quiote\Testing\UnitTestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Quiote\Config\Config;
use Quiote\Cache\CacheManager;
use Quiote\Middleware\DispatchMiddleware;
use Quiote\Execution\ActionDescriptor;
use Nyholm\Psr7\Factory\Psr17Factory;
use Quiote\Execution\ExecutionState;
use Quiote\Execution\ValidationDecision;

#[RunTestsInSeparateProcesses]
class DispatchMiddlewareExecutionStateTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('core.cache_enabled', true);
        Config::set('core.use_cache', true);
    Config::set('core.cache_dir', sys_get_temp_dir() . '/quiote_cache_test');
    $dir = Config::getString('core.cache_dir'); if(!is_dir($dir)) { @mkdir($dir, 0775, true); }
        CacheManager::reset();
        $psrDir = Config::getString('core.cache_dir') . DIRECTORY_SEPARATOR . 'psr-cache';
        if (is_dir($psrDir)) {
            $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($psrDir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($rii as $file) { $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname()); }
            @rmdir($psrDir);
        }
        $this->getContext()->getController()->initializeModule('Cache');
    }

    private function req(ActionDescriptor $descriptor, ExecutionState $state) {
        // Cache is not isSimple() (it exercises a real execute() call for
        // caching tests), so DispatchMiddleware requires a validation
        // decision -- normally set by ValidationMiddleware, which this test
        // bypasses since it targets DispatchMiddleware in isolation.
        $state->validationDecision = ValidationDecision::passed();
        $factory = new Psr17Factory();
        /** @var \Quiote\Request\WebRequest $legacyReq */
        $legacyReq = $this->getContext()->getRequest();
        $psr = $legacyReq
            ->withUri($factory->createUri('http://localhost/cache'))
            ->withMethod('GET');
        return $psr
            ->withAttribute(ActionDescriptor::class, $descriptor)
            ->withAttribute('module','Cache')
            ->withAttribute('action','Cache')
            ->withAttribute(ExecutionState::class, $state);
    }

    public function testExecutionStateCacheHitFlag()
    {
        $controller = $this->getContext()->getController();
        $controller->createActionInstance('Cache','Cache');
    $d1 = ActionDescriptor::fromController($controller,'Cache','Cache','GET', strtolower($controller->getOutputType()->getName()));
        $mw = new DispatchMiddleware($controller);
    \Sandbox\Modules\Cache\Actions\CacheAction::$execCount = 0;
        $state1 = new ExecutionState();
    $mw->process($this->req($d1, $state1), new class(new Psr17Factory) implements \Psr\Http\Server\RequestHandlerInterface { public function __construct(private $f){} public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface { return $this->f->createResponse(200);} });
    $this->assertSame(1, \Sandbox\Modules\Cache\Actions\CacheAction::$execCount, 'First run should execute action');
        $this->assertFalse($state1->cacheHit, 'cacheHit should remain false on miss');

        // Second run (cache hit)
        $controller->createActionInstance('Cache','Cache');
    $d2 = ActionDescriptor::fromController($controller,'Cache','Cache','GET', strtolower($controller->getOutputType()->getName()));
        $state2 = new ExecutionState();
    $mw->process($this->req($d2, $state2), new class(new Psr17Factory) implements \Psr\Http\Server\RequestHandlerInterface { public function __construct(private $f){} public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface { return $this->f->createResponse(200);} });
    $this->assertSame(1, \Sandbox\Modules\Cache\Actions\CacheAction::$execCount, 'Second run should not re-execute action');
        $this->assertTrue($state2->cacheHit, 'cacheHit should be true after cache replay');
    }
}
