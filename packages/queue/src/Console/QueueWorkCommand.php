<?php

namespace Quiote\Queue\Console;

use Quiote\Config\Config;
use Quiote\Console\Command\AbstractAppCommand;
use Quiote\Context;
use Quiote\Queue\PollableQueueDriverInterface;
use Quiote\Queue\QueueDriverRegistry;
use Quiote\Queue\QueueWorker;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Polls a persistent {@see PollableQueueDriverInterface} and processes jobs
 * one at a time via {@see QueueWorker}. Running this against `sync` (the
 * default driver, nothing to poll) is a misconfiguration, not a crash — it
 * fails fast with a clear message telling the operator to configure a
 * persistent driver (e.g. `quioteframework/queue-db`).
 */
#[AsCommand(name: 'queue:work', description: 'Process jobs from a persistent queue backend')]
final class QueueWorkCommand extends AbstractAppCommand
{
    protected function configure(): void
    {
        $this->configureAppOptions();
        $this->addOption('driver', null, InputOption::VALUE_REQUIRED, 'Queue driver alias to work (defaults to queue.default_driver)');
        $this->addOption('max-jobs', null, InputOption::VALUE_REQUIRED, 'Stop after processing this many jobs (default: unlimited)');
        $this->addOption('sleep', null, InputOption::VALUE_REQUIRED, 'Seconds to sleep between empty polls', '1');
        $this->addOption('stop-when-empty', null, InputOption::VALUE_NONE, 'Exit as soon as the queue is empty instead of polling');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->bootstrapApp($input);
        $io = new SymfonyStyle($input, $output);

        $context = Context::getInstance(Config::getString('core.default_context', 'web'));
        $container = $context->getContainer();

        $driverOption = $input->getOption('driver');
        $driverAlias = is_string($driverOption) && $driverOption !== '' ? $driverOption : Config::getString('queue.default_driver', 'sync');

        try {
            $driverClass = QueueDriverRegistry::instantiateClassFor($driverAlias);
        } catch (RuntimeException $e) {
            $io->error($e->getMessage());
            return self::FAILURE;
        }

        $driver = $container->get($driverClass);
        if (!$driver instanceof PollableQueueDriverInterface) {
            $io->error(sprintf(
                'Queue driver "%s" does not support polling; queue:work needs a persistent backend (e.g. quioteframework/queue-db), not "%s".',
                $driverAlias,
                $driverAlias,
            ));
            return self::FAILURE;
        }

        $worker = $container->get(QueueWorker::class);

        $maxJobsOption = $input->getOption('max-jobs');
        $maxJobs = is_string($maxJobsOption) && $maxJobsOption !== '' ? (int) $maxJobsOption : null;
        $sleepOption = $input->getOption('sleep');
        $sleepSeconds = max(0, (int) (is_string($sleepOption) ? $sleepOption : '1'));
        $stopWhenEmpty = (bool) $input->getOption('stop-when-empty');

        $io->title(sprintf('Working queue (driver: %s)', $driverAlias));

        $processed = 0;
        while ($maxJobs === null || $processed < $maxJobs) {
            if ($worker->processNext($driver)) {
                $processed++;
                continue;
            }
            if ($stopWhenEmpty) {
                break;
            }
            sleep($sleepSeconds);
        }

        $io->success(sprintf('Processed %d job(s).', $processed));
        return self::SUCCESS;
    }
}
