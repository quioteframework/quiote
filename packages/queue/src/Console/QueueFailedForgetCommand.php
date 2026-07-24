<?php

namespace Quiote\Queue\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Deletes a dead-lettered job without retrying it. */
#[AsCommand(name: 'queue:failed:forget', description: 'Delete a dead-lettered job without retrying it')]
final class QueueFailedForgetCommand extends AbstractQueueFailedCommand
{
    protected function configure(): void
    {
        $this->configureAppOptions();
        $this->addArgument('id', InputArgument::OPTIONAL, 'ID of the failed job to delete (see queue:failed:list)');
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Delete every failed job');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->bootstrapApp($input);
        $io = new SymfonyStyle($input, $output);

        $store = $this->resolveInspectableStore($this->resolveContainer(), $io);
        if ($store === null) {
            return self::FAILURE;
        }

        $id = $input->getArgument('id');
        $all = (bool) $input->getOption('all');

        if ($all) {
            $forgotten = 0;
            while (($batch = $store->list(50, 0)) !== []) {
                foreach ($batch as $record) {
                    $store->delete($record->id);
                    $forgotten++;
                }
            }
            $io->success(sprintf('Forgot %d job(s).', $forgotten));
            return self::SUCCESS;
        }

        if (!is_string($id)) {
            $io->error('Provide a job id, or --all to delete every failed job.');
            return self::FAILURE;
        }

        if ($store->find($id) === null) {
            $io->error(sprintf('No failed job with id "%s".', $id));
            return self::FAILURE;
        }

        $store->delete($id);
        $io->success(sprintf('Forgot job "%s".', $id));
        return self::SUCCESS;
    }
}
