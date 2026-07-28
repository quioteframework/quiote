<?php

declare(strict_types=1);

namespace Quiote\Runtime\Swoole\Console;

use Quiote\Console\AppDirResolver;
use Quiote\Console\Command\AbstractAppCommand;
use Quiote\Exception\QuioteException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

/**
 * Starts the app's Swoole server as a child process, the same shape as
 * {@see \Quiote\Console\Command\ServeCommand} wrapping `frankenphp php-server`.
 *
 * Deliberately a process wrapper rather than calling Kernel::run() here: the
 * console has already bootstrapped the app in *this* process, so running the
 * server inline would bootstrap twice and then fork worker children off a
 * half-initialised Context. One entrypoint, one process.
 */
#[AsCommand(name: 'swoole:serve', description: 'Run the application under an embedded Swoole HTTP server')]
final class SwooleServeCommand extends AbstractAppCommand
{
    public const DEFAULT_ENTRYPOINT = 'swoole.php';

    protected function configure(): void
    {
        $this->configureAppOptions();
        $this->addOption(
            'entrypoint',
            null,
            InputOption::VALUE_REQUIRED,
            'Server entrypoint script, relative to the application root',
            self::DEFAULT_ENTRYPOINT,
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

        if (!extension_loaded('swoole')) {
            $io->error('ext-swoole is not installed. Install it with `pecl install swoole` (5.1 or newer).');
            return self::FAILURE;
        }

        $entrypointOption = $input->getOption('entrypoint');
        $entrypoint = is_string($entrypointOption) && $entrypointOption !== ''
            ? $entrypointOption
            : self::DEFAULT_ENTRYPOINT;
        $script = $resolved['appDir'] . '/' . ltrim($entrypoint, '/');
        if (!is_file($script)) {
            $io->error(sprintf(
                '"%s" does not exist. Generate it with `quiote new --runtime=swoole`, or point --entrypoint at your own server script.',
                $script,
            ));
            return self::FAILURE;
        }

        $process = new Process([PHP_BINARY, $script], $resolved['appDir'], [
            // The runtime needs the opt-in to claim the process; see
            // SwooleRuntime::isSupported() for why loading the extension isn't
            // evidence enough on its own.
            'QUIOTE_WORKER_RUNTIME' => 'swoole',
        ] + getenv());
        $process->setTimeout(null);
        $process->setTty(Process::isTtySupported());

        $io->writeln(sprintf('Starting Swoole via %s...', $script));

        try {
            $exitCode = $process->run(static function (string $type, string $buffer) use ($output): void {
                $output->write($buffer);
            });
        } catch (\Throwable $e) {
            throw new QuioteException('Failed to start the Swoole server: ' . $e->getMessage(), previous: $e);
        }

        return $exitCode === 0 ? self::SUCCESS : self::FAILURE;
    }
}
