<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Quiote\Support\Clock\Clock;
use Quiote\Support\Clock\FrozenClock;
use Quiote\Util\WorkerManager;
use Symfony\Contracts\Service\ResetInterface;

/** Records that it was asked to reset, so "the manager reached it" is assertable. */
final class WorkerManagerResettable implements ResetInterface
{
    public int $resets = 0;

    public function reset(): void
    {
        ++$this->resets;
    }
}

/** Resettable, but refuses -- a collaborator whose cleanup itself fails. */
final class WorkerManagerFailingResettable implements ResetInterface
{
    public int $attempts = 0;

    public function reset(): void
    {
        ++$this->attempts;

        throw new RuntimeException('reset failed');
    }
}

/**
 * WorkerManager keeps the per-worker recycling configuration and statistics
 * that a persistent host runs on. Every bit of it is process-global static
 * state, so each test snapshots and restores it -- otherwise configuring a
 * cleanup interval here would change how the rest of the suite recycles.
 */
final class WorkerManagerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $configBackup = [];

    /** @var array<string, mixed> */
    private array $statisticsBackup = [];

    private int $requestCountBackup = 0;

    #[Before]
    public function captureStaticState(): void
    {
        $this->configBackup = $this->readStatic('config');
        $this->statisticsBackup = $this->readStatic('statistics');
        $count = (new ReflectionProperty(WorkerManager::class, 'requestCount'))->getValue();
        $this->requestCountBackup = is_int($count) ? $count : 0;
    }

    #[After]
    public function restoreStaticState(): void
    {
        (new ReflectionProperty(WorkerManager::class, 'config'))->setValue(null, $this->configBackup);
        (new ReflectionProperty(WorkerManager::class, 'statistics'))->setValue(null, $this->statisticsBackup);
        (new ReflectionProperty(WorkerManager::class, 'requestCount'))->setValue(null, $this->requestCountBackup);
        Clock::useClock(null);
    }

    /** @return array<string, mixed> */
    private function readStatic(string $property): array
    {
        $value = (new ReflectionProperty(WorkerManager::class, $property))->getValue();
        $this->assertIsArray($value);

        /** @var array<string, mixed> $value */
        return $value;
    }

    // --- configuration ------------------------------------------------------

    /**
     * configure() merges rather than replaces: the Kernel sets three keys for
     * a persistent runtime, and the preserved-config list it does not mention
     * has to survive that.
     */
    public function testConfigureMergesOverTheDefaultsRatherThanReplacingThem(): void
    {
        WorkerManager::configure(['max_requests_before_cleanup' => 42]);

        $config = $this->readStatic('config');
        $this->assertSame(42, $config['max_requests_before_cleanup']);
        $this->assertNotEmpty($config['preserve_config_keys'], 'the untouched defaults are still there');
    }

    public function testConfigureOverwritesOnlyTheKeysItIsGiven(): void
    {
        WorkerManager::configure(['reset_stats' => false]);
        WorkerManager::configure(['preserve_callback_pool' => false]);

        $config = $this->readStatic('config');
        $this->assertFalse($config['reset_stats'], 'the earlier change survives the later one');
        $this->assertFalse($config['preserve_callback_pool']);
    }

    public function testInitializeAppliesItsOptionsAsConfiguration(): void
    {
        WorkerManager::initialize(['max_requests_before_cleanup' => 7]);

        $this->assertSame(7, $this->readStatic('config')['max_requests_before_cleanup']);
    }

    // --- statistics ---------------------------------------------------------

    public function testInitializeStampsTheStartTimeAndCapabilityFlags(): void
    {
        WorkerManager::initialize([
            'preserve_database_connections' => true,
            'apcu_acceleration' => true,
        ]);

        $statistics = $this->readStatic('statistics');
        $this->assertGreaterThan(0.0, $statistics['start_time']);
        $this->assertTrue($statistics['db_connections_active']);
        $this->assertTrue($statistics['apcu_acceleration']);
    }

    public function testTheCapabilityFlagsDefaultToOffWhenNotDeclared(): void
    {
        WorkerManager::initialize([]);

        $statistics = $this->readStatic('statistics');
        $this->assertFalse($statistics['db_connections_active']);
        $this->assertFalse($statistics['apcu_acceleration']);
    }

    /** The reported statistics are the recorded ones plus what only makes sense live. */
    public function testGetStatisticsAddsUptimeAndMemoryToTheRecordedFigures(): void
    {
        WorkerManager::initialize([]);

        $statistics = WorkerManager::getStatistics();

        $this->assertArrayHasKey('uptime', $statistics);
        $this->assertArrayHasKey('memory_usage', $statistics);
        $this->assertArrayHasKey('memory_peak', $statistics);
        $this->assertGreaterThanOrEqual(0.0, $statistics['uptime']);
        $this->assertGreaterThan(0, $statistics['memory_usage']);
        $this->assertArrayHasKey('reset_count', $statistics, 'the recorded figures come through too');
    }

    /**
     * start_time is stamped from the monotonic clock, and uptime is measured
     * against it on the same clock -- not the wall clock, so an NTP step
     * during a long worker's life can't corrupt either figure. Verified with a
     * FrozenClock: start_time is stamped exactly where the clock stood at
     * initialize(), and uptime is exactly the gap to wherever it stands later.
     */
    public function testStartTimeAndUptimeAreMeasuredOnTheInjectedMonotonicClock(): void
    {
        $clock = new FrozenClock(1_000_000.0, 12.5);
        Clock::useClock($clock);

        WorkerManager::initialize([]);
        $this->assertSame(12.5, $this->readStatic('statistics')['start_time']);

        $clock->setMonotonic(20.0);
        $statistics = WorkerManager::getStatistics();
        $this->assertSame(7.5, $statistics['uptime']);
    }

    public function testShutdownClearsTheResetCount(): void
    {
        WorkerManager::initialize([]);
        (new ReflectionProperty(WorkerManager::class, 'statistics'))->setValue(null, [
            ...$this->readStatic('statistics'),
            'reset_count' => 17,
        ]);

        WorkerManager::shutdown();

        $this->assertSame(0, $this->readStatic('statistics')['reset_count']);
    }

    public function testTheRequestCountIsReportedAsItStands(): void
    {
        (new ReflectionProperty(WorkerManager::class, 'requestCount'))->setValue(null, 5);

        $this->assertSame(5, WorkerManager::getRequestCount());
    }

    // --- resetting collaborators --------------------------------------------

    public function testEveryResettableObjectIsReset(): void
    {
        $first = new WorkerManagerResettable();
        $second = new WorkerManagerResettable();

        WorkerManager::resetObjects(['first' => $first, 'second' => $second]);

        $this->assertSame(1, $first->resets);
        $this->assertSame(1, $second->resets);
    }

    /** A list carrying non-objects is not an error; they simply have nothing to reset. */
    public function testNonObjectsInTheListAreSkipped(): void
    {
        $resettable = new WorkerManagerResettable();

        WorkerManager::resetObjects(['scalar' => 'not an object', 'null' => null, 'ok' => $resettable]);

        $this->assertSame(1, $resettable->resets);
    }

    public function testAnObjectThatIsNotResettableIsSkippedByDefault(): void
    {
        $resettable = new WorkerManagerResettable();

        WorkerManager::resetObjects(['plain' => new stdClass(), 'ok' => $resettable]);

        $this->assertSame(1, $resettable->resets, 'the rest of the list is still reset');
    }

    /**
     * Strict mode is for a caller that assembled the list deliberately: an
     * object in it that cannot be reset is a wiring mistake worth hearing
     * about rather than a silent skip.
     */
    public function testAnObjectThatIsNotResettableIsReportedInStrictMode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not implement ResetInterface');

        WorkerManager::resetObjects(['plain' => new stdClass()], skipErrors: false);
    }

    /**
     * One collaborator failing to reset must not leave the rest of the worker
     * carrying the previous request's state, so the sweep continues.
     */
    public function testAFailingResetDoesNotAbandonTheRestOfTheList(): void
    {
        $failing = new WorkerManagerFailingResettable();
        $after = new WorkerManagerResettable();

        WorkerManager::resetObjects(['failing' => $failing, 'after' => $after]);

        $this->assertSame(1, $failing->attempts);
        $this->assertSame(1, $after->resets, 'the sweep carried on past the failure');
    }

    public function testAFailingResetIsRaisedInStrictMode(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('reset failed');

        WorkerManager::resetObjects(['failing' => new WorkerManagerFailingResettable()], skipErrors: false);
    }

    public function testAnEmptyListIsANoOp(): void
    {
        WorkerManager::resetObjects([]);

        $this->addToAssertionCount(1);
    }

    // --- database strategy ---------------------------------------------------

    /** "keep" is the default: a persistent worker reuses its connections. */
    public function testKeepingDatabaseConnectionsDoesNothing(): void
    {
        WorkerManager::manageDatabaseConnections('keep');

        $this->addToAssertionCount(1);
    }

    public function testAnUnknownStrategyIsIgnoredRatherThanFatal(): void
    {
        WorkerManager::manageDatabaseConnections('somersault');

        $this->addToAssertionCount(1);
    }
}
