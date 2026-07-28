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
 * whichever is available. Deliberately does not call {@see AbstractAppCommand::bootstrapApp()}
 * (like NewCommand, it only needs to locate pub/, not boot the app in this
 * process) -- it only needs {@see AppDirResolver} for the same --app-dir/
 * $QUIOTE_APP_DIR/marker-file resolution every other command uses.
 * @since      1.0.0
 */
#[AsCommand(name: 'serve', description: 'Run a local development server for the app (FrankenPHP if available, else php -S)')]
final class ServeCommand extends AbstractAppCommand
{
    protected function configure(): void
    {
        $this->configureAppOptions();
        $this
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host to bind', 'localhost')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'Port to bind', '8000');
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

        $frankenphp = (new ExecutableFinder())->find('frankenphp');
        if ($frankenphp !== null) {
            $process = new Process([$frankenphp, 'php-server', '--listen', "{$host}:{$port}", '--root', $pubDir]);
            $io->writeln(sprintf('Starting FrankenPHP on http://%s:%s (root: %s)...', $host, $port, $pubDir));
        } else {
            $process = new Process([PHP_BINARY, '-S', "{$host}:{$port}", '-t', $pubDir]);
            $io->note('frankenphp binary not found on PATH -- falling back to `php -S` (single-threaded, dev only). Install FrankenPHP for a closer-to-production experience.');
            $io->writeln(sprintf('Starting php -S on http://%s:%s (root: %s)...', $host, $port, $pubDir));
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
}
