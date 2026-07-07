<?php
use Quiote\Testing\UnitTestCase;
use Quiote\Config\Config;
use Quiote\Execution\SlotDispatcher;
use Quiote\Middleware\SlotMiddleware;
use Nyholm\Psr7\ServerRequest;
use Quiote\Execution\SlotStack;
use Sandbox\Modules\Snapshot\Actions\ParamSnapshotAction;

class SlotDispatcherTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    Config::set('core.cache_dir', sys_get_temp_dir() . '/quiote_cache_test');
    $dir = Config::getString('core.cache_dir'); if(!is_dir($dir)) { @mkdir($dir, 0775, true); }
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

    /**
     * isSimple() was introduced (Agavi commit f166330f4, 2007) specifically
     * for slots: "don't call execute() on the action ... only the arguments
     * set on their containers. good for slots." A truly isSimple() action
     * must never run execute() through SlotDispatcher either, matching
     * ActionExecutor::doExecute().
     */
    public function testSimpleActionSlotDispatchNeverRunsExecute()
    {
        $controller = $this->getContext()->getController();
        $controller->initializeModule('Snapshot');
        $dispatcher = new SlotDispatcher($controller);
        $req = (new ServerRequest('GET', 'http://localhost/'))
            ->withAttribute(SlotMiddleware::ATTR, new SlotStack());
        ParamSnapshotAction::$seenParams = [];
        $content = $dispatcher->dispatch($req, 'Snapshot', 'ParamSnapshotAction');
        $this->assertSame('PARAM_OK', $content, 'View must render via getDefaultViewName(), not execute()\'s return value');
        $this->assertSame([], ParamSnapshotAction::$seenParams, 'execute() must never run for a simple action dispatched as a slot');
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
