<?php

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use Quiote\Config\Config;
use Quiote\Context;
use Quiote\DI\Container;
use Quiote\Plugin\PluginManager;
use Quiote\Queue\Console\QueueFailedListCommand;
use Quiote\Queue\FailedJob;
use Quiote\Queue\FailedJobRecord;
use Quiote\Queue\FailedJobStoreInterface;
use Quiote\Queue\InspectableFailedJobStoreInterface;
use Quiote\Queue\LogFailedJobStore;
use Quiote\Queue\QueueDriverRegistry;
use Quiote\Queue\QueuePlugin;
use Quiote\Testing\PhpUnitTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class QueueFailedListCommandFakeStore implements InspectableFailedJobStoreInterface
{
    /** @var array<string, FailedJobRecord> */
    public array $records = [];

    public function record(FailedJob $failedJob): void
    {
    }

    public function list(int $limit = 50, int $offset = 0): array
    {
        return array_slice(array_values($this->records), $offset, $limit);
    }

    public function count(): int
    {
        return count($this->records);
    }

    public function find(string $id): ?FailedJobRecord
    {
        return $this->records[$id] ?? null;
    }

    public function delete(string $id): void
    {
        unset($this->records[$id]);
    }
}

/**
 * Uses a fake {@see InspectableFailedJobStoreInterface} bound directly onto
 * the sandbox app's container (overriding QueuePlugin's default
 * LogFailedJobStore) so this test doesn't need a real DB backend.
 */
final class QueueFailedListCommandTest extends PhpUnitTestCase
{
    #[Before]
    #[After]
    public function resetQueueState(): void
    {
        QueueDriverRegistry::reset();
        PluginManager::reset();
        // The command under test bootstraps the app before it resolves its
        // container, and bootstrap restores core.default_context from the app's
        // settings. Other tests in this process leave that directive pointing at
        // a different context, which would bind the fake store onto one Context's
        // container while the command reads another's -- so pin it to the context
        // this suite runs against before touching any container.
        Config::set('core.default_context', getenv('QUIOTE_ISOLATION_DEFAULT_CONTEXT') ?: 'web', true);

        // Context's Container is a process-wide singleton across the whole test
        // run, so a fake store bound by one test would otherwise leak into every
        // test that runs after it (in this file or any other).
        Context::getInstance(Config::getString('core.default_context', 'web'))
            ->getContainer()
            ->set(FailedJobStoreInterface::class, new LogFailedJobStore(), Container::SCOPE_SINGLETON);
    }

    private function tester(QueueFailedListCommandFakeStore $store): CommandTester
    {
        PluginManager::add(new QueuePlugin());
        PluginManager::bootFromConfig();
        $container = Context::getInstance(Config::getString('core.default_context', 'web'))->getContainer();
        PluginManager::configureContainer($container);
        $container->set(FailedJobStoreInterface::class, $store, Container::SCOPE_SINGLETON);

        return new CommandTester(new QueueFailedListCommand());
    }

    public function testErrorsWhenStoreDoesNotSupportInspection(): void
    {
        PluginManager::add(new QueuePlugin());
        PluginManager::bootFromConfig();
        $container = Context::getInstance(Config::getString('core.default_context', 'web'))->getContainer();
        PluginManager::configureContainer($container);

        $tester = new CommandTester(new QueueFailedListCommand());
        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        // SymfonyStyle word-wraps error output, so normalize whitespace before matching.
        $normalized = preg_replace('/\s+/', ' ', $tester->getDisplay());
        $this->assertStringContainsString('does not support inspection', (string) $normalized);
    }

    public function testReportsWhenThereAreNoFailedJobs(): void
    {
        $exitCode = $this->tester(new QueueFailedListCommandFakeStore())->execute([]);

        $this->assertSame(0, $exitCode);
    }

    public function testListsFailedJobs(): void
    {
        $store = new QueueFailedListCommandFakeStore();
        $store->records['abc123'] = new FailedJobRecord(
            id: 'abc123',
            jobClass: 'App\\Jobs\\SendWelcomeEmail',
            params: ['userId' => 5],
            exceptionClass: RuntimeException::class,
            exceptionMessage: 'boom',
            exceptionTrace: '#0 ...',
            attempts: 3,
            failedAt: new DateTimeImmutable('@1000'),
        );

        $tester = $this->tester($store);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('abc123', $display);
        $this->assertStringContainsString('App\\Jobs\\SendWelcomeEmail', $display);
        $this->assertStringContainsString('Total failed jobs: 1', $display);
    }
}
