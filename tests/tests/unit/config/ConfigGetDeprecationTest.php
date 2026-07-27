<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Config;
use Quiote\Logging\Log;
use Quiote\Logging\Level;
use Quiote\Logging\Sink\JsonStdoutSink;

require_once(__DIR__ . '/../../../../Quiote/Config/Config.php');

/**
 * Config::get() is a deprecated untyped accessor; it warns on every call.
 * That warning used to pay debug_backtrace() + a full log write on every
 * single call, with no rate limiting -- covers the per-directive warn-once
 * memoization added to fix that.
 */
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class ConfigGetDeprecationTest extends PhpUnitTestCase
{
    /** @var resource */
    private $buf;

    #[\Override]
    public function setUp(): void
    {
        Config::clear();
        Log::reset();
        $buf = fopen('php://memory', 'r+');
        if ($buf === false) {
            self::fail('Failed to open php://memory for the log buffer.');
        }
        $this->buf = $buf;
        Log::setDefaultLevel(Level::Warning);
        Log::addSink(new JsonStdoutSink(Level::Warning, [], 'php://stdout', $this->buf));
    }

    #[\Override]
    public function tearDown(): void
    {
        Log::reset();
    }

    /** @return list<array<string,mixed>> */
    private function records(): array
    {
        rewind($this->buf);
        $out = trim((string) stream_get_contents($this->buf));
        if ($out === '') {
            return [];
        }
        $records = [];
        foreach (explode("\n", $out) as $line) {
            $decoded = json_decode($line, true);
            $this->assertIsArray($decoded);
            $records[] = $decoded;
        }
        return $records;
    }

    public function testGetWarnsOnFirstCallForADirective(): void
    {
        Config::set('some.directive', 'value');
        Config::get('some.directive');

        $records = $this->records();
        $this->assertCount(1, $records);
    }

    public function testGetDoesNotWarnAgainForTheSameDirective(): void
    {
        Config::set('some.directive', 'value');
        Config::get('some.directive');
        Config::get('some.directive');
        Config::get('some.directive');

        $this->assertCount(1, $this->records(), 'repeat get() calls for the same directive must not re-warn');
    }

    public function testGetWarnsIndependentlyPerDirective(): void
    {
        Config::set('some.directive', 'value');
        Config::set('other.directive', 'value2');
        Config::get('some.directive');
        Config::get('other.directive');
        Config::get('some.directive');
        Config::get('other.directive');

        $this->assertCount(2, $this->records(), 'each distinct directive warns exactly once');
    }

    public function testGetStillReturnsTheConfiguredValueAfterWarnOnceSuppression(): void
    {
        Config::set('some.directive', 'value');
        Config::get('some.directive');

        $this->assertSame('value', Config::get('some.directive'));
    }

    public function testGetReturnsDefaultForMissingDirective(): void
    {
        $this->assertSame('fallback', Config::get('missing.directive', 'fallback'));
        // Still warns once even though the directive was never set.
        $this->assertCount(1, $this->records());
    }
}
