<?php
declare(strict_types=1);

namespace Quiote\Console\Command;

use Quiote\Console\Command\Scaffold\GeneratorSupport;
use Quiote\Exception\ConfigurationException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Scaffolds a `Quiote\Queue\Job` implementation, optionally
 * `Quiote\Queue\RetryableJob` for a per-job retry policy.
 * @since      1.0.0
 */
#[AsCommand(name: 'make:job', description: 'Scaffold a queue Job class')]
final class MakeJobCommand extends AbstractAppCommand
{
    protected function configure(): void
    {
        $this->configureAppOptions();
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Job name, e.g. "SendWelcomeEmail"')
            ->addOption('retryable', null, InputOption::VALUE_NONE, 'Implement RetryableJob instead of Job')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite an existing file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->bootstrapApp($input);

        $retryable = (bool) $input->getOption('retryable');
        $force = (bool) $input->getOption('force');

        try {
            $name = GeneratorSupport::requireString($input->getArgument('name'), 'name');
            GeneratorSupport::validateClassNameSegment($name);
            $namespace = GeneratorSupport::appNamespace();
            $path = GeneratorSupport::appDir() . "/Jobs/{$name}Job.php";
            GeneratorSupport::guardOverwrite($path, $force);
            GeneratorSupport::writeFile($path, $this->jobPhp($namespace, $name, $retryable));
        } catch (ConfigurationException $e) {
            $io->error($e->getMessage());
            return self::FAILURE;
        }

        if (!interface_exists(\Quiote\Queue\Job::class)) {
            $io->note('quioteframework/queue is not installed -- the generated class will not autoload until you `composer require` it.');
        }

        $io->success(sprintf('Created %sJob.', $name));
        return self::SUCCESS;
    }

    private function jobPhp(string $namespace, string $name, bool $retryable): string
    {
        if (!$retryable) {
            return <<<PHP
<?php
namespace {$namespace}\\Jobs;

use Quiote\\Queue\\Job;

final class {$name}Job implements Job
{
	public function handle(): void
	{
	}
}

PHP;
        }

        return <<<PHP
<?php
namespace {$namespace}\\Jobs;

use Quiote\\Queue\\RetryableJob;

final class {$name}Job implements RetryableJob
{
	public function handle(): void
	{
	}

	public function maxAttempts(): int
	{
		return 3;
	}

	public function backoffSeconds(int \$attempt): int
	{
		return \$attempt * 30;
	}
}

PHP;
    }
}
