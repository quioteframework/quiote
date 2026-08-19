<?php

declare(strict_types=1);

namespace Quiote\Replay\Console;

use Quiote\Console\Command\AbstractAppCommand;
use Quiote\Replay\Cassette\CassetteId;
use Quiote\Replay\Index\IndexHints;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `quiote cassette:fetch <id>` -- the explicit fetch-only verb: resolve $id to a cassette (local
 * cache, then the configured store, then the cassette-index chain) and keep it in the local cache
 * without replaying it. `quiote replay <id> --save` is the same operation under `ReplayCommand`'s
 * own name, for the "I saw this failure in a log viewer, get it onto my machine" reflex mid-replay
 * workflow; this command is for scripting or when a replay is not wanted at all.
 */
#[AsCommand(name: 'cassette:fetch', description: 'Resolve a cassette id to a store key and download it into the local cache')]
final class CassetteFetchCommand extends AbstractAppCommand
{
    use ResolvesCassetteViaIndexes;

    protected function configure(): void
    {
        $this->configureAppOptions();
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'The cassette id')
            ->addOption('key', null, InputOption::VALUE_REQUIRED, 'An exact store key pasted from a pointer log line, bypassing id-based resolution')
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'A YYYY-MM-DD hint narrowing a prefix scan to one day')
            ->addOption('hour', null, InputOption::VALUE_REQUIRED, 'An 00-23 hint narrowing a prefix scan to one hour of --date')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Local directory to cache the cassette in (defaults to replay.local_path)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->bootstrapApp($input);
        $io = new SymfonyStyle($input, $output);

        $idArgument = $input->getArgument('id');
        if (!is_string($idArgument) || $idArgument === '') {
            $io->error('A cassette id is required.');

            return self::FAILURE;
        }

        $id = CassetteId::fromRaw($idArgument);
        $hints = new IndexHints(
            key: self::stringOption($input, 'key'),
            date: self::stringOption($input, 'date'),
            hour: self::stringOption($input, 'hour'),
        );

        $result = $this->fetchCassette($io, $id, $hints, self::stringOption($input, 'to'));
        if ($result === null) {
            return self::FAILURE;
        }

        if ($input->getOption('json')) {
            $output->writeln(json_encode([
                'id' => $idArgument,
                'source' => $result['source'],
                'cached_path' => $result['cached_path'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

            return self::SUCCESS;
        }

        $io->success(sprintf('Fetched cassette "%s" from %s.', $idArgument, $result['source']));
        if ($result['cached_path'] !== null) {
            $io->writeln(sprintf('Cached at: %s', $result['cached_path']));
        }

        return self::SUCCESS;
    }
}
