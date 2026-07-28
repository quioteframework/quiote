<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Quiote\Config\Config;
use Quiote\Plugin\PluginManager;
use Quiote\Runtime\RoadRunner\RoadRunnerRuntime;
use Quiote\Runtime\RoadRunner\WorkerRoadRunnerPlugin;
use Quiote\Runtime\Worker\WorkerRuntimeRegistry;

final class WorkerRoadRunnerPluginTest extends TestCase
{
    #[Before]
    #[After]
    public function resetState(): void
    {
        PluginManager::reset();
        WorkerRuntimeRegistry::reset();
        Config::remove('worker.roadrunner.chunk_size');
    }

    public function testRegisteringThePluginAddsTheRuntimeAliasAndItsDefaults(): void
    {
        $this->assertFalse(WorkerRuntimeRegistry::has('roadrunner'));

        PluginManager::add(new WorkerRoadRunnerPlugin());
        PluginManager::bootFromConfig();

        $this->assertTrue(WorkerRuntimeRegistry::has('roadrunner'));
        $this->assertSame(RoadRunnerRuntime::class, WorkerRuntimeRegistry::resolve('roadrunner'));
        $this->assertSame(8192, Config::getInt('worker.roadrunner.chunk_size'));
    }

    public function testTheAliasResolvesThroughInstantiateClassForOnceRegistered(): void
    {
        PluginManager::add(new WorkerRoadRunnerPlugin());
        PluginManager::bootFromConfig();

        $this->assertSame(RoadRunnerRuntime::class, WorkerRuntimeRegistry::instantiateClassFor('roadrunner'));
    }

    public function testAnAppSuppliedChunkSizeIsNotOverwritten(): void
    {
        Config::set('worker.roadrunner.chunk_size', 512);

        PluginManager::add(new WorkerRoadRunnerPlugin());
        PluginManager::bootFromConfig();

        // configDefault() is set-if-absent, so app settings win.
        $this->assertSame(512, Config::getInt('worker.roadrunner.chunk_size'));
    }

    public function testTheRuntimeIsNotSelectableWithoutThePluginRegisteringIt(): void
    {
        // Installing the package is not enough; activation is config-driven like
        // every other Quiote plugin.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/No runtime alias by that name is registered/');
        WorkerRuntimeRegistry::instantiateClassFor('roadrunner');
    }
}
