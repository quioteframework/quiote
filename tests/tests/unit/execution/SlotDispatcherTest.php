<?php
use Quiote\Testing\UnitTestCase;
use Quiote\Config\Config;
use Quiote\Execution\SlotDispatcher;
use Quiote\Middleware\SlotMiddleware;
use Nyholm\Psr7\ServerRequest;
use Quiote\Execution\SlotStack;

class SlotDispatcherTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    Config::set('core.cache_dir', sys_get_temp_dir() . '/quiote_cache_test');
    $dir = Config::get('core.cache_dir'); if(!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $this->getContext()->getController()->initializeModule('Cache');
    // Force action class load for tests (autoload bridging still in transition)
    $this->getContext()->getController()->createActionInstance('Cache','Cache');
    }

    public function testSimpleSlotDispatch()
    {
        $controller = $this->getContext()->getController();
    // Ensure module initialization loads the action class
    $controller->initializeModule('Cache');
        $dispatcher = new SlotDispatcher($controller);
        $req = (new ServerRequest('GET', 'http://localhost/'))
            ->withAttribute(SlotMiddleware::ATTR, new SlotStack());
    \Sandbox\Modules\Cache\Actions\CacheAction::$execCount = 0;
    $content = $dispatcher->dispatch($req, 'Cache', 'Cache');
    $this->assertNotSame('', $content);
    $this->assertStringContainsString('CACHE_', $content);
    $this->assertSame(1, \Sandbox\Modules\Cache\Actions\CacheAction::$execCount);
    }

    public function testRecursionLimit()
    {
        $controller = $this->getContext()->getController();
        $dispatcher = new SlotDispatcher($controller);
        $stack = new SlotStack();
        // Pre-populate stack to simulate deep recursive chain
        for($i=0;$i<=SlotDispatcher::RECURSION_LIMIT; $i++) { $stack->push('Cache/Cache'); }
        $req = (new ServerRequest('GET', 'http://localhost/'))
            ->withAttribute(SlotMiddleware::ATTR, $stack);
        $content = $dispatcher->dispatch($req, 'Cache', 'Cache');
        $this->assertSame('', $content);
    }
}
