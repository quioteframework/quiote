<?php

namespace Quiote\Queue\Console;

use Quiote\Queue\FailedJobRecord;
use Quiote\Queue\InspectableFailedJobStoreInterface;
use Quiote\Queue\Job;
use Quiote\Queue\QueueManager;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Re-pushes a dead-lettered job and removes it from the failed store. */
#[AsCommand(name: 'queue:failed:retry', description: 'Re-push a dead-lettered job onto the queue')]
final class QueueFailedRetryCommand extends AbstractQueueFailedCommand
{
    protected function configure(): void
    {
        $this->configureAppOptions();
        $this->addArgument('id', InputArgument::OPTIONAL, 'ID of the failed job to retry (see queue:failed:list)');
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Retry every failed job');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->bootstrapApp($input);
        $io = new SymfonyStyle($input, $output);

        $container = $this->resolveContainer();
        $store = $this->resolveInspectableStore($container, $io);
        if ($store === null) {
            return self::FAILURE;
        }

        $queueManager = $container->get(QueueManager::class);
        if (!$queueManager instanceof QueueManager) {
            $io->error(sprintf('Expected "%s" service to be a QueueManager, got %s.', QueueManager::class, get_debug_type($queueManager)));
            return self::FAILURE;
        }

        $id = $input->getArgument('id');
        $all = (bool) $input->getOption('all');

        if ($all) {
            $retried = 0;
            while (($batch = $store->list(50, 0)) !== []) {
                foreach ($batch as $record) {
                    $this->retryOne($record, $store, $queueManager);
                    $retried++;
                }
            }
            $io->success(sprintf('Retried %d job(s).', $retried));
            return self::SUCCESS;
        }

        if (!is_string($id)) {
            $io->error('Provide a job id, or --all to retry every failed job.');
            return self::FAILURE;
        }

        $record = $store->find($id);
        if ($record === null) {
            $io->error(sprintf('No failed job with id "%s".', $id));
            return self::FAILURE;
        }

        $this->retryOne($record, $store, $queueManager);
        $io->success(sprintf('Retried job "%s" (%s).', $record->id, $record->jobClass));
        return self::SUCCESS;
    }

    private function retryOne(FailedJobRecord $record, InspectableFailedJobStoreInterface $store, QueueManager $queueManager): void
    {
        if (!is_a($record->jobClass, Job::class, true)) {
            throw new RuntimeException(sprintf(
                'Failed job "%s" has job_class "%s", which no longer exists or does not implement %s.',
                $record->id,
                $record->jobClass,
                Job::class,
            ));
        }

        $queueManager->push($record->jobClass, $record->params);
        $store->delete($record->id);
    }
}
