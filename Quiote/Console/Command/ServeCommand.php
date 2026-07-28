<?php
declare(strict_types=1);

namespace Quiote\Console\Command;

use Quiote\Console\AppDirResolver;
use Quiote\Console\Command\Scaffold\GeneratorSupport;
use Quiote\Exception\QuioteException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Dev-server convenience wrapper: `quiote new`'s own "next steps" just tell
 * the user to run `php -S` or `frankenphp php-server` by hand -- this runs
 * whichever is available, and `--runtime` reaches the CLI-hosted servers too so
 * there is one entry point for every deployment shape Quiote supports.
 *
 * Deliberately does not call {@see AbstractAppCommand::bootstrapApp()}
 * (like NewCommand, it only needs to locate pub/, not boot the app in this
 * process) -- it only needs {@see AppDirResolver} for the same --app-dir/
 * $QUIOTE_APP_DIR/marker-file resolution every other command uses.
 * @since      1.0.0
 */
#[AsCommand(name: 'serve', description: 'Run a local development server for the app (FrankenPHP if available, else php -S)')]
final class ServeCommand extends AbstractAppCommand
{
    public const RUNTIME_AUTO = 'auto';
    public const RUNTIME_FRANKENPHP = 'frankenphp';
    public const RUNTIME_PHP = 'php';
    public const RUNTIME_ROADRUNNER = 'roadrunner';
    public const RUNTIME_SWOOLE = 'swoole';

    /** @var list<string> */
    public const RUNTIMES = [
        self::RUNTIME_AUTO,
        self::RUNTIME_FRANKENPHP,
        self::RUNTIME_PHP,
        self::RUNTIME_ROADRUNNER,
        self::RUNTIME_SWOOLE,
    ];

    protected function configure(): void
    {
        $this->configureAppOptions();
        $this
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host to bind', 'localhost')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'Port to bind', '8000')
            ->addOption(
                'runtime',
                null,
                InputOption::VALUE_REQUIRED,
                'Which server to run: auto (FrankenPHP if present, else php -S), frankenphp, php, roadrunner, swoole',
                self::RUNTIME_AUTO,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $appDirOption = $input->getOption('app-dir');
        $envOption = $input->getOption('env');
        $resolved = AppDirResolver::resolve(
            is_string($appDirOption) && $appDirOption !== '' ? $appDirOption : null,
            is_string($envOption) && $envOption !== '' ? $envOption : null,
        );
        if ($resolved['appDir'] === null) {
            $io->error('Could not locate a Quiote application. Pass --app-dir, set $QUIOTE_APP_DIR, or run this from inside an application directory.');
            return self::FAILURE;
        }
        $pubDir = $resolved['appDir'] . '/pub';
        if (!is_dir($pubDir)) {
            $io->error(sprintf('"%s" does not exist -- expected the app\'s public/document root there.', $pubDir));
            return self::FAILURE;
        }

        $host = GeneratorSupport::requireString($input->getOption('host'), '--host');
        $port = GeneratorSupport::requireString($input->getOption('port'), '--port');
        $runtime = GeneratorSupport::requireString($input->getOption('runtime'), '--runtime');
        if (!in_array($runtime, self::RUNTIMES, true)) {
            $io->error(sprintf('Unknown --runtime "%s"; expected one of: %s.', $runtime, implode(', ', self::RUNTIMES)));
            return self::FAILURE;
        }

        $process = $this->buildProcess($runtime, $io, $resolved['appDir'], $pubDir, $host, $port);
        if ($process === null) {
            return self::FAILURE;
        }

        $process->setTimeout(null);
        $process->setTty(Process::isTtySupported());

        try {
            $exitCode = $process->run(function (string $type, string $buffer) use ($output): void {
                $output->write($buffer);
            });
        } catch (\Throwable $e) {
            throw new QuioteException('Failed to start the development server: ' . $e->getMessage(), previous: $e);
        }

        return $exitCode === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Every branch is a thin wrapper around the server's own binary or the app's
     * own entrypoint script, never an in-process Kernel::run(): the console has
     * already bootstrapped the app here, so serving inline would bootstrap twice
     * (and, for Swoole, then fork off a half-initialised Context).
     *
     * @return Process|null Null when the requested runtime can't be started, after reporting why.
     */
    private function buildProcess(
        string $runtime,
        SymfonyStyle $io,
        string $appDir,
        string $pubDir,
        string $host,
        string $port,
    ): ?Process {
        $finder = new ExecutableFinder();

        if ($runtime === self::RUNTIME_AUTO) {
            $runtime = $finder->find('frankenphp') !== null ? self::RUNTIME_FRANKENPHP : self::RUNTIME_PHP;
            if ($runtime === self::RUNTIME_PHP) {
                $io->note('frankenphp binary not found on PATH -- falling back to `php -S` (single-threaded, dev only). Install FrankenPHP for a closer-to-production experience.');
            }
        }

        switch ($runtime) {
            case self::RUNTIME_FRANKENPHP:
                $frankenphp = $finder->find('frankenphp');
                if ($frankenphp === null) {
                    $io->error('The frankenphp binary is not on PATH. Install FrankenPHP, or use --runtime=php.');
                    return null;
                }
                $io->writeln(sprintf('Starting FrankenPHP on http://%s:%s (root: %s)...', $host, $port, $pubDir));
                return new Process([$frankenphp, 'php-server', '--listen', "{$host}:{$port}", '--root', $pubDir]);

            case self::RUNTIME_ROADRUNNER:
                // `rr serve` reads .rr.yaml from the app root, which is where the
                // address and the worker command are configured -- so --host/--port
                // deliberately do not apply here.
                $rr = $finder->find('rr') ?? (is_file($appDir . '/vendor/bin/rr') ? $appDir . '/vendor/bin/rr' : null);
                if ($rr === null) {
                    $io->error('The rr binary was not found. Install it with `composer require --dev spiral/roadrunner-cli && vendor/bin/rr get-binary`.');
                    return null;
                }
                if (!is_file($appDir . '/.rr.yaml')) {
                    $io->error(sprintf('"%s/.rr.yaml" does not exist. Generate it with `quiote new --runtime=roadrunner`, or write one by hand.', $appDir));
                    return null;
                }
                $io->writeln('Starting RoadRunner (address and workers come from .rr.yaml)...');
                return new Process([$rr, 'serve'], $appDir);

            case self::RUNTIME_SWOOLE:
                if (!extension_loaded('swoole')) {
                    $io->error('ext-swoole is not installed. Install it with `pecl install swoole` (5.1 or newer).');
                    return null;
                }
                $script = $appDir . '/swoole.php';
                if (!is_file($script)) {
                    $io->error(sprintf('"%s" does not exist. Generate it with `quiote new --runtime=swoole`.', $script));
                    return null;
                }
                $io->writeln(sprintf('Starting Swoole via %s (address comes from worker.swoole.* settings)...', $script));
                return new Process([PHP_BINARY, $script], $appDir, ['QUIOTE_WORKER_RUNTIME' => 'swoole'] + getenv());

            default:
                $io->writeln(sprintf('Starting php -S on http://%s:%s (root: %s)...', $host, $port, $pubDir));
                return new Process([PHP_BINARY, '-S', "{$host}:{$port}", '-t', $pubDir]);
        }
    }
}
