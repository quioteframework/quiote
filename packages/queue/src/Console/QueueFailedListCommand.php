<?php

namespace Quiote\Queue\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Lists jobs that exhausted their retries (see {@see \Quiote\Queue\InspectableFailedJobStoreInterface}). */
#[AsCommand(name: 'queue:failed:list', description: 'List jobs that exhausted their retries')]
final class QueueFailedListCommand extends AbstractQueueFailedCommand
{
    protected function configure(): void
    {
        $this->configureAppOptions();
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum rows to show', '50');
        $this->addOption('offset', null, InputOption::VALUE_REQUIRED, 'Rows to skip', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->bootstrapApp($input);
        $io = new SymfonyStyle($input, $output);

        $store = $this->resolveInspectableStore($this->resolveContainer(), $io);
        if ($store === null) {
            return self::FAILURE;
        }

        $limitOption = $input->getOption('limit');
        $offsetOption = $input->getOption('offset');
        $limit = (int) (is_string($limitOption) ? $limitOption : '50');
        $offset = (int) (is_string($offsetOption) ? $offsetOption : '0');

        $records = $store->list($limit, $offset);
        if ($records === []) {
            $io->success('No failed jobs.');
            return self::SUCCESS;
        }

        $io->table(
            ['ID', 'Job class', 'Attempts', 'Failed at', 'Exception'],
            array_map(static fn($r) => [
                $r->id,
                $r->jobClass,
                (string) $r->attempts,
                $r->failedAt->format('Y-m-d H:i:s'),
                sprintf('%s: %s', $r->exceptionClass, $r->exceptionMessage),
            ], $records),
        );
        $io->writeln(sprintf('Total failed jobs: <info>%d</info>', $store->count()));

        return self::SUCCESS;
    }
}
