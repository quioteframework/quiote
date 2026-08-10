<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Quiote\Logging\CategoryLogger;
use Quiote\Logging\Level;
use Quiote\Logging\Log;
use Quiote\Logging\LogEvent;
use Quiote\Logging\LogRegistry;
use Quiote\Logging\Sink\SinkInterface;

/** Accepts everything, so what a logger decides to emit is what gets recorded. */
final class LogReconfigurationSink implements SinkInterface
{
    /** @var list<LogEvent> */
    public array $captured = [];

    public function isEnabled(Level $level, string $category): bool
    {
        return true;
    }

    public function emit(LogEvent $event): void
    {
        $this->captured[] = $event;
    }

    public function flush(): void
    {
    }
}

/**
 * A CategoryLogger memoizes its threshold and its per-level isEnabled()
 * answers, and the instances are shared per category and live as long as the
 * worker. Reconfiguring levels or adding a sink afterwards therefore has to
 * reach loggers that were already handed out -- which is exactly what
 * Log::setDefaultLevel()/setLevel() promise.
 *
 * The order matters in every test here: the logger is acquired and used
 * *before* the reconfiguration, so its caches are populated and stale.
 */
final class LogReconfigurationTest extends TestCase
{
    #[Before]
    #[After]
    public function resetLogging(): void
    {
        LogRegistry::reset();
    }

    private function sink(): LogReconfigurationSink
    {
        $sink = new LogReconfigurationSink();
        Log::addSink($sink);

        return $sink;
    }

    public function testRaisingACategoryLevelReachesALoggerAlreadyHandedOut(): void
    {
        $sink = $this->sink();
        $logger = Log::create('Quiote.Reconfig');

        $this->assertFalse($logger->isEnabled(Level::Debug), 'debug is below the default level');

        Log::setLevel('Quiote.Reconfig', Level::Debug);

        $this->assertTrue($logger->isEnabled(Level::Debug), 'the new rule must reach this logger');

        $logger->debug('now visible');
        $this->assertCount(1, $sink->captured);
    }

    public function testLoweringACategoryLevelAlsoReachesALoggerAlreadyHandedOut(): void
    {
        $sink = $this->sink();
        $logger = Log::create('Quiote.Reconfig');
        $logger->info('visible at the default level');
        $this->assertCount(1, $sink->captured);

        Log::setLevel('Quiote.Reconfig', Level::Error);

        $this->assertFalse($logger->isEnabled(Level::Info));
        $logger->info('now suppressed');
        $this->assertCount(1, $sink->captured, 'nothing further may be emitted');
    }

    public function testChangingTheDefaultLevelReachesALoggerAlreadyHandedOut(): void
    {
        $this->sink();
        $logger = Log::create('Quiote.Reconfig');
        $this->assertFalse($logger->isEnabled(Level::Debug));

        Log::setDefaultLevel(Level::Debug);

        $this->assertTrue($logger->isEnabled(Level::Debug));
    }

    public function testSetLevelsReachesALoggerAlreadyHandedOut(): void
    {
        $this->sink();
        $logger = Log::create('Quiote.Reconfig');
        $this->assertFalse($logger->isEnabled(Level::Debug));

        Log::setLevels(['Quiote.Reconfig' => Level::Debug]);

        $this->assertTrue($logger->isEnabled(Level::Debug));
    }

    /**
     * isEnabled() is "would any sink emit this", so it depends on the sink
     * list too -- a logger that answered false because nothing was registered
     * has to answer true once something is.
     */
    public function testRegisteringASinkReachesALoggerThatAlreadyAnsweredWithoutOne(): void
    {
        $logger = Log::create('Quiote.Reconfig');
        $this->assertFalse($logger->isEnabled(Level::Error), 'no sink is registered yet');

        $sink = $this->sink();

        $this->assertTrue($logger->isEnabled(Level::Error));
        $logger->error('now recorded');
        $this->assertCount(1, $sink->captured);
    }

    /** Resetting the configuration is a change like any other. */
    public function testResettingTheConfigurationReachesALoggerAlreadyHandedOut(): void
    {
        $this->sink();
        $logger = Log::create('Quiote.Reconfig');
        Log::setLevel('Quiote.Reconfig', Level::Debug);
        $this->assertTrue($logger->isEnabled(Level::Debug));

        LogRegistry::reset();

        $this->assertFalse($logger->isEnabled(Level::Debug), 'the debug rule is gone with the reset');
    }

    /**
     * The re-resolution is keyed to a generation counter, so a logger used
     * repeatedly without any configuration change resolves once and then
     * answers from its cache.
     */
    public function testAnUnchangedConfigurationResolvesOnlyOnce(): void
    {
        $this->sink();
        $logger = Log::create('Quiote.Reconfig');
        $logger->isEnabled(Level::Error);

        $generationBefore = LogRegistry::generation();
        for ($i = 0; $i < 5; $i++) {
            $logger->isEnabled(Level::Error);
        }

        $this->assertSame($generationBefore, LogRegistry::generation(), 'reading must not bump the generation');
        $this->assertSame(
            $generationBefore,
            (new ReflectionProperty(CategoryLogger::class, 'resolvedAt'))->getValue($logger),
            'the logger stays resolved at that generation',
        );
    }

    /** Loggers are shared per category, so the change reaches every holder of one. */
    public function testTheChangeReachesEveryHolderOfTheSharedLogger(): void
    {
        $this->sink();
        $first = Log::create('Quiote.Reconfig');
        $second = Log::create('Quiote.Reconfig');
        $this->assertSame($first, $second, 'loggers are cached per category');

        $first->isEnabled(Level::Debug);
        Log::setLevel('Quiote.Reconfig', Level::Debug);

        $this->assertTrue($second->isEnabled(Level::Debug));
    }
}
