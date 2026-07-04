<?php

use Quiote\Console\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `telemetry:dashboard`'s real path runs `Tui::run()` forever until
 * `q`/SIGINT and needs a real TTY (raw terminal mode) -- not something
 * CommandTester can drive. `--self-test` (see the command's own docblock)
 * renders exactly one frame with no receiver socket and no TUI loop, which
 * is what this test exercises: proof the command is registered, wired to
 * `DashboardView`, and produces output, without needing a TTY, a port, or a
 * running event loop.
 */
final class TelemetryDashboardCommandTest extends TestCase
{
    private function tester(): CommandTester
    {
        $application = new Application();

        return new CommandTester($application->find('telemetry:dashboard'));
    }

    public function testCommandIsRegisteredWhenSymfonyTuiIsInstalled(): void
    {
        $application = new Application();

        $this->assertTrue($application->has('telemetry:dashboard'));
    }

    public function testSelfTestRendersAFrameAndExitsSuccessfully(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->execute(['--self-test' => true, '--service' => 'demo-app']);

        $this->assertSame(0, $exitCode);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('demo-app', $display);
        $this->assertStringContainsString('Waiting for telemetry', $display);
    }

    public function testSelfTestReflectsCustomHostAndPortInTheListeningAddress(): void
    {
        $tester = $this->tester();
        $tester->execute(['--self-test' => true, '--host' => '0.0.0.0', '--port' => '9999']);

        $this->assertStringContainsString('0.0.0.0:9999', $tester->getDisplay());
    }
}
