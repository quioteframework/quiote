<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use Quiote\Logging\Log;
use Quiote\Logging\Level;
use Quiote\Logging\LogContext;
use Quiote\Logging\LogRegistry;
use Quiote\Logging\Sink\JsonStdoutSink;

/**
 * Core tests for the PSR-3 logging subsystem.
 */
class LoggingTest extends TestCase
{
    /** @var resource */
    private $buf;

    #[Before]
    public function setUpLogging(): void
    {
        Log::reset();
        LogContext::clear();
        $buf = fopen('php://memory', 'r+');
        if ($buf === false) {
            self::fail('Failed to open php://memory for the logging test buffer.');
        }
        $this->buf = $buf;
    }

    #[After]
    public function tearDownLogging(): void
    {
        Log::reset();
        LogContext::clear();
    }

    private function sink(Level $min = Level::Debug): JsonStdoutSink
    {
        return new JsonStdoutSink($min, [], 'php://stdout', $this->buf);
    }

    /** @return list<array<string,mixed>> decoded JSON records emitted so far */
    private function records(): array
    {
        rewind($this->buf);
        $out = trim((string) stream_get_contents($this->buf));
        if ($out === '') {
            return [];
        }
        $records = [];
        foreach (explode("\n", $out) as $line) {
            // Each event must be exactly one physical line and valid JSON.
            $this->assertSame(1, substr_count($line, "\n") + 1);
            $decoded = json_decode($line, true);
            $this->assertIsArray($decoded, "line is valid JSON: $line");
            $records[] = $decoded;
        }
        return $records;
    }

    // --- Level ------------------------------------------------------------

    public function testLevelPsrRoundTrip(): void
    {
        foreach (['debug','info','notice','warning','error','critical','alert','emergency'] as $psr) {
            $this->assertSame($psr, Level::fromPsr($psr)->toPsr());
        }
        // Trace has no PSR equivalent and degrades to debug.
        $this->assertSame('debug', Level::Trace->toPsr());
    }

    public function testLevelFromNameAliases(): void
    {
        $this->assertSame(Level::Warning, Level::fromName('warn'));
        $this->assertSame(Level::Warning, Level::fromName('WARNING'));
        $this->assertSame(Level::Info, Level::fromName('information'));
        $this->assertSame(Level::Emergency, Level::fromName('fatal'));
        $this->expectException(\InvalidArgumentException::class);
        Level::fromName('nope');
    }

    public function testLevelOrdering(): void
    {
        $this->assertTrue(Level::Error->passes(Level::Warning));
        $this->assertFalse(Level::Debug->passes(Level::Info));
        $this->assertTrue(Level::Info->passes(Level::Info));
    }

    // --- Category resolution ---------------------------------------------

    public function testLongestPrefixWins(): void
    {
        Log::setDefaultLevel(Level::Info);
        Log::setLevels([
            'Quiote'         => Level::Warning,
            'Quiote.Routing' => Level::Debug,
            'App.Orders'    => Level::Debug,
        ]);
        $this->assertSame(Level::Debug,   LogRegistry::resolveLevel('Quiote.Routing'));
        $this->assertSame(Level::Debug,   LogRegistry::resolveLevel('Quiote.Routing.Matcher')); // inherits prefix
        $this->assertSame(Level::Warning, LogRegistry::resolveLevel('Quiote.Security'));
        $this->assertSame(Level::Info,    LogRegistry::resolveLevel('App.Misc'));               // default
        // "Quiote" prefix must NOT match a different top-level segment.
        $this->assertSame(Level::Info,    LogRegistry::resolveLevel('Extras.Thing'));
    }

    public function testForNormalizesFqcnToDottedCategory(): void
    {
        $logger = Log::for('App\\Orders\\OrderService');
        $this->assertSame('App.Orders.OrderService', $logger->category());
    }

    // --- Gating -----------------------------------------------------------

    public function testIsEnabledGatesByCategoryThreshold(): void
    {
        Log::setDefaultLevel(Level::Info);
        Log::setLevel('Quiote', Level::Warning);
        Log::addSink($this->sink(Level::Debug));

        $routing = Log::create('Quiote.Routing'); // Warning
        $this->assertTrue($routing->isEnabled(Level::Warning));
        $this->assertFalse($routing->isEnabled(Level::Info));
    }

    public function testIsEnabledFalseWithNoSinks(): void
    {
        Log::setDefaultLevel(Level::Debug);
        $this->assertFalse(Log::create('X')->isEnabled(Level::Error), 'no sink => nothing emitted');
    }

    public function testSinkMinLevelFiltersIndependently(): void
    {
        Log::setDefaultLevel(Level::Debug);         // category allows debug
        Log::addSink($this->sink(Level::Warning));  // but sink only warning+
        $log = Log::create('App');
        $this->assertFalse($log->isEnabled(Level::Info));
        $this->assertTrue($log->isEnabled(Level::Error));
    }

    public function testBelowThresholdEmitsNothing(): void
    {
        Log::setDefaultLevel(Level::Warning);
        Log::addSink($this->sink(Level::Debug));
        Log::create('App')->info('dropped');
        $this->assertSame([], $this->records());
    }

    // --- Emission / structure --------------------------------------------

    public function testStructuredEmissionWithInterpolationAndScope(): void
    {
        Log::setDefaultLevel(Level::Debug);
        Log::addSink($this->sink(Level::Debug));

        $scope = LogContext::push(['rid' => 'req-123']);
        Log::create('App.Orders')->warning('order {id} for {who}', ['id' => 42, 'who' => 'acme']);
        $scope->close();

        $records = $this->records();
        $this->assertCount(1, $records);
        $r = $records[0];
        $this->assertSame('order 42 for acme', $r['message']);
        $this->assertSame('order {id} for {who}', $r['template']);
        $this->assertSame('warning', $r['level']);
        $this->assertSame('App.Orders', $r['category']);
        $this->assertSame('app', $r['src']);
        $this->assertSame(42, $r['id']);        // property flattened
        $this->assertSame('req-123', $r['rid']); // scope merged
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $r['ts']);
    }

    public function testExceptionCaptured(): void
    {
        Log::setDefaultLevel(Level::Debug);
        Log::addSink($this->sink(Level::Debug));

        $e = new \RuntimeException('boom', 7, new \LogicException('root cause'));
        Log::create('App')->error('failed', ['exception' => $e]);

        $r = $this->records()[0];
        $this->assertArrayNotHasKey('exception', $r['exception'] ?? [], 'exception key not left in properties');
        $this->assertSame('RuntimeException', $r['exception']['chain'][0]['class']);
        $this->assertSame(7, $r['exception']['chain'][0]['code']);
        $this->assertSame('LogicException', $r['exception']['chain'][1]['class'], 'cause chain flattened');
        $this->assertIsString($r['exception']['trace']);
    }

    public function testReservedKeysWinOverUserProperties(): void
    {
        Log::setDefaultLevel(Level::Debug);
        Log::addSink($this->sink(Level::Debug));
        Log::create('App')->info('hi', ['level' => 'not-a-level', 'category' => 'nope']);
        $r = $this->records()[0];
        $this->assertSame('info', $r['level'], 'reserved level not clobbered by a property');
        $this->assertSame('App', $r['category']);
    }

    public function testMultilineValueStaysOnePhysicalLine(): void
    {
        Log::setDefaultLevel(Level::Debug);
        Log::addSink($this->sink(Level::Debug));
        Log::create('App')->error('bad', ['detail' => "line1\nline2\nline3"]);
        // records() already asserts each event is a single physical line.
        $r = $this->records()[0];
        $this->assertSame("line1\nline2\nline3", $r['detail']);
    }

    // --- Worker-mode scope isolation -------------------------------------

    public function testScopeClearedBetweenRequests(): void
    {
        Log::setDefaultLevel(Level::Debug);
        Log::addSink($this->sink(Level::Debug));

        // "Request A" — request-lifetime enricher (no token; cleared on reset).
        LogContext::enrich(['rid' => 'A', 'userId' => 1]);
        Log::create('App')->info('during A');

        // Worker reset between requests.
        LogContext::clear();
        $this->assertTrue(LogContext::isEmpty());

        // "Request B" must not inherit A's scope.
        Log::create('App')->info('during B');

        $records = $this->records();
        $this->assertSame('A', $records[0]['rid']);
        $this->assertArrayNotHasKey('rid', $records[1], 'scope leaked across worker reset');
        $this->assertArrayNotHasKey('userId', $records[1]);
    }

    public function testEnrichHasNoTokenAndSurvivesUntilClear(): void
    {
        LogContext::enrich(['rid' => 'req-9']);
        $this->assertSame(['rid' => 'req-9'], LogContext::current());
        LogContext::clear();
        $this->assertTrue(LogContext::isEmpty());
    }

    public function testUnheldPushTokenPopsImmediately(): void
    {
        // Documenting the RAII sharp edge: not assigning push()'s return value
        // destroys the token at end of statement, popping the frame at once.
        LogContext::push(['x' => 1]);
        $this->assertTrue(LogContext::isEmpty(), 'unheld push() token must pop immediately');
    }

    public function testScopeTokenPopsOnlyItsFrame(): void
    {
        $a = LogContext::push(['a' => 1]);
        $b = LogContext::push(['b' => 2]);
        $a->close(); // close outer first (out-of-order)
        $this->assertSame(['b' => 2], LogContext::current(), 'closing A must not drop B');
        $b->close();
        $this->assertTrue(LogContext::isEmpty());
    }
}
