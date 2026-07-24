<?php

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Quiote\Config\Config;
use Quiote\Plugin\PluginManager;
use Quiote\Queue\Db\DbQueueDriver;
use Quiote\Queue\Db\QueueDbPlugin;
use Quiote\Queue\QueueDriverRegistry;

/**
 * QueueDbPlugin::register() -- config defaults and the `db` driver alias.
 * Does not exercise the container-service factories (they need a real,
 * `initialize()`d DatabaseManager via Context — see DbQueueDriverTest/
 * DbFailedJobStoreTest for the PDO-level behavior instead).
 */
final class QueueDbPluginTest extends TestCase
{
    #[Before]
    #[After]
    public function resetState(): void
    {
        PluginManager::reset();
        QueueDriverRegistry::reset();
        Config::remove('queue.db.connection');
        Config::remove('queue.db.table');
        Config::remove('queue.db.failed_table');
    }

    public function testRegistersDefaultConfig(): void
    {
        PluginManager::add(new QueueDbPlugin());
        PluginManager::bootFromConfig();

        $this->assertSame('main', Config::getString('queue.db.connection'));
        $this->assertSame('quiote_queue_jobs', Config::getString('queue.db.table'));
        $this->assertSame('quiote_queue_failed_jobs', Config::getString('queue.db.failed_table'));
    }

    public function testRegistersTheDbDriverAlias(): void
    {
        PluginManager::add(new QueueDbPlugin());
        PluginManager::bootFromConfig();

        $this->assertTrue(QueueDriverRegistry::has('db'));
        $this->assertSame(DbQueueDriver::class, QueueDriverRegistry::resolve('db'));
    }
}
