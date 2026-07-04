<?php

use PHPUnit\Framework\TestCase;
use Quiote\Config\Config;
use Quiote\Logging\Level;
use Quiote\Logging\Log;
use Quiote\Logging\LogEvent;
use Quiote\Logging\Sink\SinkInterface;
use Quiote\Quiote;

/**
 * Quiote::bootstrap() logs a startup warning for two silently-misleading
 * states: `telemetry.enabled=true` with no real provider active (the
 * telemetry-otel plugin never got a chance to build one -- package not
 * installed, or installed but not added to `plugins`), and CSRF explicitly
 * disabled via `core.csrf.enabled=false` (the deliberate "conscious effort"
 * opt-out). Neither warning should fire
 * on the happy path. Runs in separate processes: bootstrap() locks
 * core.environment/core.app_dir read-only and mutates several other static
 * registries (PluginManager, TraceRegistry, Log's sinks) that must not leak
 * between test methods.
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
final class QuioteBootstrapWarningsTest extends TestCase
{
    private SinkInterface $sink;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sink = new class implements SinkInterface {
            /** @var list<LogEvent> */
            public array $captured = [];
            public function isEnabled(Level $level, string $category): bool
            {
                return $level->value >= Level::Warning->value;
            }
            public function emit(LogEvent $event): void
            {
                $this->captured[] = $event;
            }
            public function flush(): void {}
        };
        Log::addSink($this->sink);

        Config::set('core.app_dir', dirname(__DIR__, 3) . '/sandbox/app', true, true);
        Config::set('core.config_dir', Config::get('core.app_dir') . '/Config', true, true);
    }

    /** @return list<LogEvent> */
    private function bootstrapAndCaptureWarnings(): array
    {
        Quiote::bootstrap('testing');
        return array_values(array_filter(
            $this->sink->captured,
            static fn(LogEvent $e): bool => $e->category === 'Quiote.Quiote' && $e->level === Level::Warning
        ));
    }

    /** @param list<LogEvent> $warnings */
    private function messages(array $warnings): array
    {
        return array_map(static fn(LogEvent $e): string => $e->renderMessage(), $warnings);
    }

    public function testWarnsWhenTelemetryEnabledButNoProviderIsActive(): void
    {
        // tests/bootstrap.php globally disables CSRF for the suite -- pin it
        // back on here so only the telemetry warning is under test.
        Config::set('core.csrf.enabled', true);
        Config::set('telemetry.enabled', true);

        $messages = $this->messages($this->bootstrapAndCaptureWarnings());

        $matches = array_filter($messages, static fn(string $m): bool =>
            str_contains($m, 'telemetry.enabled is true but no real telemetry provider is active'));
        $this->assertNotEmpty($matches, 'expected a warning about telemetry.enabled with no active provider');
    }

    public function testNoTelemetryWarningWhenDisabled(): void
    {
        Config::set('core.csrf.enabled', true);
        Config::set('telemetry.enabled', false);

        $messages = $this->messages($this->bootstrapAndCaptureWarnings());

        $matches = array_filter($messages, static fn(string $m): bool => str_contains($m, 'telemetry.enabled'));
        $this->assertSame([], array_values($matches));
    }

    public function testWarnsWhenCsrfExplicitlyDisabled(): void
    {
        Config::set('core.csrf.enabled', false);

        $messages = $this->messages($this->bootstrapAndCaptureWarnings());

        $matches = array_filter($messages, static fn(string $m): bool =>
            str_contains($m, 'CSRF protection is explicitly disabled'));
        $this->assertNotEmpty($matches, 'expected a warning about core.csrf.enabled=false');
    }

    public function testNoWarningsOnHappyPath(): void
    {
        // CSRF explicitly re-enabled (countering tests/bootstrap.php's
        // suite-wide default) and telemetry left off -- zero Quiote.Quiote
        // warnings expected.
        Config::set('core.csrf.enabled', true);
        Config::set('telemetry.enabled', false);

        $warnings = $this->bootstrapAndCaptureWarnings();

        $this->assertSame([], $warnings);
    }
}
