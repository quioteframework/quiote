<?php

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Quiote\Config\Config;
use Quiote\DI\Container;
use Quiote\Plugin\PluginManager;
use Quiote\Queue\FailedJobStoreInterface;
use Quiote\Queue\JobExecutor;
use Quiote\Queue\LogFailedJobStore;
use Quiote\Queue\QueueConfig;
use Quiote\Queue\QueueManager;
use Quiote\Queue\QueuePlugin;
use Quiote\Queue\QueueWorker;

/**
 * QueuePlugin::register() -- config defaults + the DI services `queue:work`
 * and app code (via QueueManager) depend on. Mirrors AuthPluginTest's shape.
 */
final class QueuePluginTest extends TestCase
{
    #[Before]
    #[After]
    public function resetState(): void
    {
        PluginManager::reset();
        Config::remove('queue.default_driver');
        Config::remove('queue.retry.max_attempts');
        Config::remove('queue.retry.backoff_seconds');
    }

    public function testRegistersDefaultConfig(): void
    {
        PluginManager::add(new QueuePlugin());
        PluginManager::bootFromConfig();

        $this->assertSame('sync', Config::getString('queue.default_driver'));
        $this->assertSame(3, Config::getInt('queue.retry.max_attempts'));
        $this->assertSame(5, Config::getInt('queue.retry.backoff_seconds'));
    }

    public function testWiresQueueServicesIntoTheContainer(): void
    {
        PluginManager::add(new QueuePlugin());
        PluginManager::bootFromConfig();

        $container = new Container();
        PluginManager::configureContainer($container);

        $this->assertInstanceOf(LogFailedJobStore::class, $container->get(FailedJobStoreInterface::class));
        $this->assertInstanceOf(QueueConfig::class, $container->get(QueueConfig::class));
        $this->assertInstanceOf(JobExecutor::class, $container->get(JobExecutor::class));
        $this->assertInstanceOf(QueueWorker::class, $container->get(QueueWorker::class));
        $this->assertInstanceOf(QueueManager::class, $container->get(QueueManager::class));
    }

    public function testContributesTheWorkCommand(): void
    {
        PluginManager::add(new QueuePlugin());
        PluginManager::bootFromConfig();

        $this->assertContains(\Quiote\Queue\Console\QueueWorkCommand::class, PluginManager::contributedCommands());
    }
}
