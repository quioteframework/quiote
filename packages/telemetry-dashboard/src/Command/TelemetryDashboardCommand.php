<?php
declare(strict_types=1);

namespace Quiote\Console\Command;

use Quiote\Telemetry\Dashboard\DashboardState;
use Quiote\Telemetry\Dashboard\DashboardView;
use Quiote\Telemetry\Dashboard\OtlpDecoder;
use Quiote\Telemetry\Dashboard\OtlpReceiver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Tui;

/**
 * Live monitoring for a Quiote app's OTLP telemetry (docs/OPENTELEMETRY_PLAN.md)
 * -- see docs/TELEMETRY_DASHBOARD_PLAN.md for the full design. The dashboard
 * *is* a minimal OTLP/HTTP receiver: point an app's `telemetry.otlp.endpoint`
 * at this process and its existing spans/metrics export here directly, no
 * external Collector needed.
 *
 * This command owns the only two things `DashboardView` doesn't: I/O (the
 * `Tui` instance, the receiver) and the refresh loop. Everything about what
 * gets drawn lives in the pure `DashboardView::build()`; this class just
 * wires ingestion -> `DashboardState` -> periodic re-render, and keybindings
 * (`q` quit, `c` clear). Registered only when `symfony/tui` is installed
 * (guarded in `Application::__construct()`).
 *
 * `--self-test` builds and renders exactly one frame from an empty
 * `DashboardState` -- no receiver socket bound, no `Tui`/raw-terminal-mode
 * loop entered -- and exits immediately. The real `execute()` path otherwise
 * runs `Tui::run()` forever until `q`/SIGINT, which needs a real TTY
 * (`stty raw -echo`) and can't be driven by `CommandTester` in CI; this flag
 * is what makes the command itself smoke-testable there.
 * @since      1.0.0
 */
#[AsCommand(name: 'telemetry:dashboard', description: "Live dashboard for a Quiote app's OTLP telemetry")]
final class TelemetryDashboardCommand extends Command
{
    private const REFRESH_INTERVAL_SECONDS = 0.25;

    protected function configure(): void
    {
        $this
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Address to bind the OTLP receiver on', '127.0.0.1')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'Port to bind the OTLP receiver on', '4318')
            ->addOption('service', null, InputOption::VALUE_REQUIRED, 'Service name label shown in the header until telemetry resource attributes provide one', 'quiote-app')
            ->addOption('self-test', null, InputOption::VALUE_NONE, 'Render one frame from an empty state and exit -- no receiver, no TUI loop, no TTY required (for CI smoke-testing)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $host = (string) $input->getOption('host');
        $port = (int) $input->getOption('port');
        $configuredService = (string) $input->getOption('service');

        if ($input->getOption('self-test')) {
            return $this->selfTest($output, $configuredService, $host, $port);
        }

        $state = new DashboardState();
        $serviceName = $configuredService;

        $receiver = new OtlpReceiver(
            $host,
            $port,
            new OtlpDecoder(),
            function (array $spans) use (&$state, &$serviceName): void {
                $state->ingestSpans($spans, time());
                foreach ($spans as $span) {
                    $serviceName = $span->serviceName() ?? $serviceName;
                }
            },
            function (array $metrics) use (&$state): void {
                $state->ingestMetrics($metrics, time());
            },
        );

        try {
            $receiver->start();
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }

        $tui = new Tui();
        $tui->add(DashboardView::build($state->snapshot(time()), $serviceName, $receiver->endpoint()));

        $tui->addListener(function (InputEvent $event) use ($tui): void {
            if (str_contains($event->getData(), 'q')) {
                $tui->stop();
            }
        });
        $tui->addListener(function (InputEvent $event) use (&$state): void {
            if (str_contains($event->getData(), 'c')) {
                $state = new DashboardState();
            }
        });

        $tui->scheduleInterval(function () use ($tui, &$state, &$serviceName, $receiver): void {
            $tui->clear()
                ->add(DashboardView::build($state->snapshot(time()), $serviceName, $receiver->endpoint()))
                ->requestRender();
        }, self::REFRESH_INTERVAL_SECONDS);

        // Deliberately pcntl_signal(), not EventLoop::onSignal(): see
        // docs/TELEMETRY_DASHBOARD_PLAN.md's Phase 1 findings -- a
        // stream_select() blocked with no timers registered does not
        // reliably wake up early for an incoming signal via
        // pcntl_signal_dispatch(). Once the refresh interval above is
        // scheduled, select() always has a bounded timeout every iteration,
        // but there is no reason to maintain two signal-handling mechanisms
        // for what is otherwise the same "stop everything" action.
        if (function_exists('pcntl_async_signals') && defined('SIGINT')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, function () use ($tui, $receiver): void {
                $receiver->stop();
                $tui->stop();
            });
        }

        $tui->run();
        $receiver->stop();

        return self::SUCCESS;
    }

    private function selfTest(OutputInterface $output, string $serviceName, string $host, int $port): int
    {
        $tree = DashboardView::build(
            (new DashboardState())->snapshot(time()),
            $serviceName,
            sprintf('http://%s:%d', $host, $port),
        );

        foreach ((new Renderer())->render($tree, 100, 30) as $line) {
            $output->writeln($line);
        }

        return self::SUCCESS;
    }
}
