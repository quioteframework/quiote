<?php

declare(strict_types=1);

use Quiote\Config\Config;
use Quiote\Execution\SlotDispatcher;
use Quiote\Middleware\SlotMiddleware;
use Quiote\Support\Environment\EnvironmentReaderInterface;
use Quiote\Testing\UnitTestCase;
use Nyholm\Psr7\ServerRequest;
use Quiote\Execution\SlotStack;
use Quiote\Cache\CacheManager;

/**
 * The slot-cache gate's `QUIOTE_SLOT_CACHE` read goes through the injected
 * EnvironmentReaderInterface seam rather than a direct getenv() call, per
 * §6.5 of the record/replay determinism plan -- so a replay engine can
 * reproduce whether slot caching was active for a given recorded request.
 */
final class SlotDispatcherEnvironmentTest extends UnitTestCase
{
    private ?bool $origUseCache = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->origUseCache = Config::getBool('core.use_cache', false);
        Config::set('core.use_cache', true);
        CacheManager::reset();
        $controller = $this->getContext()->getContainer()->get(\Quiote\Controller\Controller::class);
        $controller->initializeModule('Cache');
        $controller->createActionInstance('Cache', 'Cache');
        \Sandbox\Modules\Cache\Actions\CacheAction::$execCount = 0;
    }

    protected function tearDown(): void
    {
        Config::set('core.use_cache', $this->origUseCache ?? false);
        CacheManager::reset();
        parent::tearDown();
    }

    private function readerReturning(string|false $value): EnvironmentReaderInterface
    {
        return new class($value) implements EnvironmentReaderInterface {
            public function __construct(private readonly string|false $value)
            {
            }

            public function get(string $name): string|false
            {
                return $name === 'QUIOTE_SLOT_CACHE' ? $this->value : false;
            }
        };
    }

    public function testAnEnvironmentReaderReturningATruthyValueEnablesSlotCaching(): void
    {
        $controller = $this->getContext()->getContainer()->get(\Quiote\Controller\Controller::class);
        $dispatcher = new SlotDispatcher($controller, environment: $this->readerReturning('1'));
        $request = (new ServerRequest('GET', 'http://localhost/'))
            ->withAttribute(SlotMiddleware::ATTR, new SlotStack());

        $dispatcher->dispatch($request, 'Cache', 'Cache', ['k' => 'v']);
        $dispatcher->dispatch($request, 'Cache', 'Cache', ['k' => 'v']);

        $this->assertSame(1, \Sandbox\Modules\Cache\Actions\CacheAction::$execCount, 'the second identical dispatch should be a cache hit');
    }

    public function testAnEnvironmentReaderReturningFalseDisablesSlotCaching(): void
    {
        $controller = $this->getContext()->getContainer()->get(\Quiote\Controller\Controller::class);
        $dispatcher = new SlotDispatcher($controller, environment: $this->readerReturning(false));
        $request = (new ServerRequest('GET', 'http://localhost/'))
            ->withAttribute(SlotMiddleware::ATTR, new SlotStack());

        $dispatcher->dispatch($request, 'Cache', 'Cache', ['k' => 'v']);
        $dispatcher->dispatch($request, 'Cache', 'Cache', ['k' => 'v']);

        $this->assertSame(2, \Sandbox\Modules\Cache\Actions\CacheAction::$execCount, 'without the cache flag, each dispatch must execute again');
    }
}
