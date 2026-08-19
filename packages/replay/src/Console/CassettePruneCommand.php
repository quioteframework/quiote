<?php

declare(strict_types=1);

namespace Quiote\Replay\Console;

use Quiote\Config\Config;
use Quiote\Console\Command\AbstractAppCommand;
use Quiote\Replay\Cassette\CassetteId;
use Quiote\Replay\Store\ListableCassetteStoreInterface;
use Quiote\Support\Clock\SystemClock;
use Quiote\Support\Compiler\Diagnostic;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `cassette:prune [--older-than=30d] [--keep=500]` -- unnecessary on Azure (a
 * blob lifecycle rule does it without anything running in the cluster), so
 * this exists for the file and PDO stores instead, via whichever
 * {@see ListableCassetteStoreInterface} `replay.store` resolves to.
 *
 * `--older-than` and `--keep` compose rather than one overriding the other:
 * a cassette is deleted if it is older than the cutoff *or* falls outside
 * the newest `--keep` cassettes (by `recorded_at`, newest first) --
 * whichever rule is given. Neither given defaults to `--older-than`
 * computed from `replay.retention_days` (default 14). A cassette with no
 * `recorded_at` (a `#[NoRecord]` skeleton, or one that predates that field)
 * is never matched by `--older-than` -- there is nothing to compare -- but
 * can still be pruned by `--keep`.
 */
#[AsCommand(name: 'cassette:prune', description: 'Delete old cassettes from the configured replay store')]
final class CassettePruneCommand extends AbstractAppCommand
{
    use ResolvesCassetteStore;
    use CollectsCassetteRows;

    protected function configure(): void
    {
        $this->configureAppOptions();
        $this
            ->addOption('older-than', null, InputOption::VALUE_REQUIRED, 'Delete cassettes recorded more than this long ago (e.g. "30d", "24h", "90m"); defaults to replay.retention_days')
            ->addOption('keep', null, InputOption::VALUE_REQUIRED, 'Keep only this many most-recently-recorded cassettes, deleting the rest')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be deleted without deleting anything')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->bootstrapApp($input);
        $io = new SymfonyStyle($input, $output);

        $store = $this->resolveCassetteStore($io);
        if ($store === null) {
            return self::FAILURE;
        }
        if (!$store instanceof ListableCassetteStoreInterface) {
            $io->error(sprintf('The configured cassette store (%s) cannot be pruned.', $store::class));

            return self::FAILURE;
        }

        $olderThanSeconds = $this->resolveOlderThanSeconds($input, $io);
        if ($olderThanSeconds === false) {
            return self::FAILURE;
        }

        $keep = $this->resolveKeep($input, $io);
        if ($keep === false) {
            return self::FAILURE;
        }

        [$unsortedRows, $diagnostics] = $this->collectCassetteRows($store);

        // Sorted by parsed timestamp, not the recorded_at string: RecorderMiddleware formats it in
        // PHP's default timezone (via SystemClock::now(), not forced to UTC), so two cassettes
        // recorded across a timezone-offset difference would sort wrong under a plain string
        // comparison even though both are valid ISO-8601.
        $pairs = array_map(
            static fn(array $row): array => [$row, self::recordedAtTimestamp($row['recorded_at'])],
            $unsortedRows,
        );
        usort($pairs, static fn(array $a, array $b): int => ($b[1] ?? 0) <=> ($a[1] ?? 0));
        $rows = array_column($pairs, 0);

        $cutoffTimestamp = (new SystemClock())->unixTimestamp() - $olderThanSeconds;
        $toDelete = [];
        foreach ($pairs as $index => [$row, $recordedAtTimestamp]) {
            $isOld = $recordedAtTimestamp !== null && $recordedAtTimestamp < $cutoffTimestamp;
            $isBeyondKeep = $keep !== null && $index >= $keep;
            if ($isOld || $isBeyondKeep) {
                $toDelete[] = $row;
            }
        }

        $dryRun = (bool)$input->getOption('dry-run');
        if (!$dryRun) {
            foreach ($toDelete as $row) {
                $store->delete(CassetteId::fromRaw($row['slug']));
            }
        }

        if ($input->getOption('json')) {
            $output->writeln(json_encode([
                'dry_run' => $dryRun,
                'deleted' => array_map(static fn(array $row): string => $row['id'], $toDelete),
                'remaining' => count($rows) - count($toDelete),
                'diagnostics' => array_map(static fn(Diagnostic $d): array => $d->toArray(), $diagnostics),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

            return $this->exitCodeFor($diagnostics);
        }

        foreach ($diagnostics as $diagnostic) {
            $io->warning($diagnostic->message);
        }
        if ($toDelete === []) {
            $io->success('Nothing to prune.');

            return $this->exitCodeFor($diagnostics);
        }
        $io->writeln(sprintf(
            '%s %d cassette%s, keeping %d.',
            $dryRun ? 'Would delete' : 'Deleted',
            count($toDelete),
            count($toDelete) === 1 ? '' : 's',
            count($rows) - count($toDelete),
        ));
        foreach ($toDelete as $row) {
            $io->writeln('  - ' . $row['id']);
        }

        return $this->exitCodeFor($diagnostics);
    }

    /** @return int|false Seconds, or false on a parse error (already reported to $io). */
    private function resolveOlderThanSeconds(InputInterface $input, SymfonyStyle $io): int|false
    {
        $option = $input->getOption('older-than');
        if (!is_string($option) || $option === '') {
            return Config::getInt('replay.retention_days', 14) * 86400;
        }

        $seconds = self::parseDuration($option);
        if ($seconds === null) {
            $io->error(sprintf('Could not parse --older-than value "%s" (expected e.g. "30d", "24h", "90m", "45s").', $option));

            return false;
        }

        return $seconds;
    }

    /** @return int|null|false Null means "no --keep given"; false on a parse error (already reported to $io). */
    private function resolveKeep(InputInterface $input, SymfonyStyle $io): int|null|false
    {
        $option = $input->getOption('keep');
        if (!is_string($option) || $option === '') {
            return null;
        }
        if (!ctype_digit($option)) {
            $io->error(sprintf('--keep must be a non-negative integer, got "%s".', $option));

            return false;
        }

        return (int)$option;
    }

    /**
     * A plain non-negative integer followed by one of s/m/h/d, e.g. "30d". Null when unparseable.
     *
     * The digit count is bounded because the multiplication below overflows to a float past
     * `PHP_INT_MAX`, and this method is declared `?int` under `strict_types` -- so an absurd
     * `--older-than=99999999999999999999d` raised a `TypeError` rather than a usage error. Nine
     * digits of days is a little over two million years, which is past any retention policy.
     */
    private static function parseDuration(string $value): ?int
    {
        if (preg_match('/^(\d{1,9})(s|m|h|d)$/', trim($value), $matches) !== 1) {
            return null;
        }
        $amount = (int)$matches[1];
        $unitSeconds = match ($matches[2]) {
            's' => 1,
            'm' => 60,
            'h' => 3600,
            'd' => 86400,
        };

        return $amount * $unitSeconds;
    }

    /** @param list<Diagnostic> $diagnostics */
    private function exitCodeFor(array $diagnostics): int
    {
        foreach ($diagnostics as $diagnostic) {
            if ($diagnostic->severity === Diagnostic::SEVERITY_ERROR) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
