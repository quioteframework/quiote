<?php

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use Quiote\Config\Config;
use Quiote\Context;
use Quiote\DI\Container;
use Quiote\Plugin\PluginManager;
use Quiote\Queue\Console\QueueFailedForgetCommand;
use Quiote\Queue\FailedJob;
use Quiote\Queue\FailedJobRecord;
use Quiote\Queue\FailedJobStoreInterface;
use Quiote\Queue\InspectableFailedJobStoreInterface;
use Quiote\Queue\LogFailedJobStore;
use Quiote\Queue\QueueDriverRegistry;
use Quiote\Queue\QueuePlugin;
use Quiote\Testing\PhpUnitTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class QueueFailedForgetCommandFakeStore implements InspectableFailedJobStoreInterface
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

final class QueueFailedForgetCommandTest extends PhpUnitTestCase
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

    private function record(string $id): FailedJobRecord
    {
        return new FailedJobRecord(
            id: $id,
            jobClass: 'App\\Jobs\\Whatever',
            params: [],
            exceptionClass: RuntimeException::class,
            exceptionMessage: 'boom',
            exceptionTrace: '#0 ...',
            attempts: 3,
            failedAt: new DateTimeImmutable('@1000'),
        );
    }

    private function tester(QueueFailedForgetCommandFakeStore $store): CommandTester
    {
        PluginManager::add(new QueuePlugin());
        PluginManager::bootFromConfig();
        $container = Context::getInstance(Config::getString('core.default_context', 'web'))->getContainer();
        PluginManager::configureContainer($container);
        $container->set(FailedJobStoreInterface::class, $store, Container::SCOPE_SINGLETON);

        return new CommandTester(new QueueFailedForgetCommand());
    }

    public function testErrorsWhenNeitherIdNorAllIsGiven(): void
    {
        $exitCode = $this->tester(new QueueFailedForgetCommandFakeStore())->execute([]);

        $this->assertSame(1, $exitCode);
    }

    public function testErrorsWhenIdIsNotFound(): void
    {
        $exitCode = $this->tester(new QueueFailedForgetCommandFakeStore())->execute(['id' => 'missing']);

        $this->assertSame(1, $exitCode);
    }

    public function testForgetsASingleJob(): void
    {
        $store = new QueueFailedForgetCommandFakeStore();
        $store->records['abc'] = $this->record('abc');

        $exitCode = $this->tester($store)->execute(['id' => 'abc']);

        $this->assertSame(0, $exitCode);
        $this->assertNull($store->find('abc'));
    }

    public function testForgetAllDeletesEveryFailedJob(): void
    {
        $store = new QueueFailedForgetCommandFakeStore();
        $store->records['a'] = $this->record('a');
        $store->records['b'] = $this->record('b');

        $exitCode = $this->tester($store)->execute(['--all' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, $store->count());
    }
}
