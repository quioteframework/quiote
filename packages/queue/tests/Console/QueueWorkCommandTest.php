<?php

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use Quiote\Config\Config;
use Quiote\Context;
use Quiote\Plugin\PluginManager;
use Quiote\Queue\Console\QueueWorkCommand;
use Quiote\Queue\QueueDriverRegistry;
use Quiote\Queue\QueuePlugin;
use Quiote\Testing\PhpUnitTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Covers `queue:work`'s CLI-level failure paths, which don't need a
 * persistent driver plugin active: an unregistered alias, and the always
 * -registered `sync` driver (which is deliberately not pollable — see
 * {@see \Quiote\Queue\SyncQueueDriver}). Happy-path job processing is
 * covered by {@see \QueueWorkerTest} against a fake
 * {@see \Quiote\Queue\PollableQueueDriverInterface}. QueuePlugin is wired
 * into the sandbox app's already-built container (rather than relying on a
 * second `Quiote::bootstrap()`, which is a no-op once already booted) so
 * `JobExecutor`'s `FailedJobStoreInterface` dependency resolves.
 */
final class QueueWorkCommandTest extends PhpUnitTestCase
{
    #[Before]
    #[After]
    public function resetQueueState(): void
    {
        QueueDriverRegistry::reset();
        PluginManager::reset();
    }

    private function tester(): CommandTester
    {
        PluginManager::add(new QueuePlugin());
        PluginManager::bootFromConfig();
        $container = Context::getInstance(Config::getString('core.default_context', 'web'))->getContainer();
        PluginManager::configureContainer($container);

        return new CommandTester(new QueueWorkCommand());
    }

    public function testFailsFastOnUnregisteredDriverAlias(): void
    {
        $exitCode = $this->tester()->execute(['--driver' => 'not-a-real-driver']);

        $this->assertSame(1, $exitCode);
    }

    public function testFailsFastWhenDriverDoesNotSupportPolling(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->execute(['--driver' => 'sync']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('does not support polling', $tester->getDisplay());
    }
}
