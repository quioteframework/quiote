<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Quiote\Config\Config;
use Quiote\Plugin\PluginManager;
use Quiote\Runtime\Swoole\Console\SwooleServeCommand;
use Quiote\Runtime\Swoole\SwooleRuntime;
use Quiote\Runtime\Swoole\WorkerSwoolePlugin;
use Quiote\Runtime\Worker\WorkerRuntimeRegistry;

final class WorkerSwoolePluginTest extends TestCase
{
    #[Before]
    #[After]
    public function resetState(): void
    {
        PluginManager::reset();
        WorkerRuntimeRegistry::reset();
        foreach ([
            'worker.swoole.host',
            'worker.swoole.port',
            'worker.swoole.worker_num',
            'worker.swoole.enable_coroutine',
            'worker.swoole.max_request',
            'worker.swoole.package_max_length',
            'worker.swoole.script_name',
            'worker.swoole.ssl',
        ] as $key) {
            Config::remove($key);
        }
    }

    public function testRegisteringThePluginAddsTheRuntimeAlias(): void
    {
        $this->assertFalse(WorkerRuntimeRegistry::has('swoole'));

        PluginManager::add(new WorkerSwoolePlugin());
        PluginManager::bootFromConfig();

        $this->assertTrue(WorkerRuntimeRegistry::has('swoole'));
        $this->assertSame(SwooleRuntime::class, WorkerRuntimeRegistry::instantiateClassFor('swoole'));
    }

    public function testTheDefaultsFavourSafetyOverThroughput(): void
    {
        PluginManager::add(new WorkerSwoolePlugin());
        PluginManager::bootFromConfig();

        $this->assertSame('0.0.0.0', Config::getString('worker.swoole.host'));
        $this->assertSame(8080, Config::getInt('worker.swoole.port'));
        // Coroutines off and one worker: Quiote's process-global state means
        // concurrency comes from more processes, not interleaved requests.
        $this->assertFalse(Config::getBool('worker.swoole.enable_coroutine'));
        $this->assertSame(1, Config::getInt('worker.swoole.worker_num'));
        $this->assertSame('/index.php', Config::getString('worker.swoole.script_name'));
        $this->assertFalse(Config::getBool('worker.swoole.ssl'));
    }

    public function testTheServeCommandIsContributed(): void
    {
        PluginManager::add(new WorkerSwoolePlugin());
        PluginManager::bootFromConfig();

        $this->assertContains(SwooleServeCommand::class, PluginManager::contributedCommands());
    }

    public function testAppSettingsAreNotOverwrittenByTheDefaults(): void
    {
        Config::set('worker.swoole.port', 9999);
        Config::set('worker.swoole.worker_num', 16);

        PluginManager::add(new WorkerSwoolePlugin());
        PluginManager::bootFromConfig();

        $this->assertSame(9999, Config::getInt('worker.swoole.port'));
        $this->assertSame(16, Config::getInt('worker.swoole.worker_num'));
    }

    public function testTheRuntimeIsNotSelectableWithoutThePlugin(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/No runtime alias by that name is registered/');
        WorkerRuntimeRegistry::instantiateClassFor('swoole');
    }
}
