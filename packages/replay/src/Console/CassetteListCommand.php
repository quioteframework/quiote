<?php

declare(strict_types=1);

namespace Quiote\Replay\Console;

use Quiote\Console\Command\AbstractAppCommand;
use Quiote\Replay\Store\ListableCassetteStoreInterface;
use Quiote\Support\Compiler\Diagnostic;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `cassette:list` -- enumerates the configured store, via whichever
 * {@see ListableCassetteStoreInterface} `replay.store` resolves to (see
 * {@see ResolvesCassetteStore}) -- the file store's own directory listing,
 * or `quioteframework/replay-pdo`'s table, today; an object-store-backed one
 * would use its own `listObjects()` instead (§12.8), not this interface.
 *
 * `--stale` (§9's console surface) is deliberately not offered yet: staleness
 * is a comparison against `meta.source_hash`, and no cassette this package
 * writes carries one yet (that needs `AppIntrospectionCompiler`'s hashing,
 * out of scope for this step) -- a flag that could never filter anything
 * meaningfully would be worse than no flag.
 */
#[AsCommand(name: 'cassette:list', description: 'List cassettes in the configured replay store')]
final class CassetteListCommand extends AbstractAppCommand
{
    use ResolvesCassetteStore;
    use CollectsCassetteRows;

    protected function configure(): void
    {
        $this->configureAppOptions();
        $this
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Only show cassettes recorded at/after this ISO-8601 timestamp')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Only show cassettes with this response status (e.g. "500") or status class (e.g. "5xx")')
            ->addOption('route', null, InputOption::VALUE_REQUIRED, 'Only show cassettes resolved to this route name')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON instead of a table');
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
            $io->error(sprintf('The configured cassette store (%s) cannot be listed.', $store::class));

            return self::FAILURE;
        }

        [$rows, $diagnostics] = $this->collectCassetteRows($store);

        $since = $input->getOption('since');
        if (is_string($since) && $since !== '') {
            $rows = array_values(array_filter($rows, static fn(array $row): bool => $row['recorded_at'] !== null && $row['recorded_at'] >= $since));
        }
        $statusFilter = $input->getOption('status');
        if (is_string($statusFilter) && $statusFilter !== '') {
            $rows = array_values(array_filter($rows, static fn(array $row): bool => self::matchesStatusFilter($row['status'], $statusFilter)));
        }
        $routeFilter = $input->getOption('route');
        if (is_string($routeFilter) && $routeFilter !== '') {
            $rows = array_values(array_filter($rows, static fn(array $row): bool => $row['route'] === $routeFilter));
        }

        usort($rows, static fn(array $a, array $b): int => ($b['recorded_at'] ?? '') <=> ($a['recorded_at'] ?? ''));

        if ($input->getOption('json')) {
            $output->writeln(json_encode([
                'cassettes' => $rows,
                'diagnostics' => array_map(static fn(Diagnostic $d): array => $d->toArray(), $diagnostics),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{"cassettes":[],"diagnostics":[]}');

            return $this->exitCodeFor($diagnostics);
        }

        foreach ($diagnostics as $diagnostic) {
            $io->warning($diagnostic->message);
        }
        if ($rows === []) {
            $io->warning('No cassettes found.');

            return $this->exitCodeFor($diagnostics);
        }
        $io->table(
            ['Id', 'Recorded at', 'Route', 'Status', 'Trigger'],
            array_map(static fn(array $row): array => [
                $row['id'], $row['recorded_at'] ?? '', $row['route'] ?? '', $row['status'] ?? '', $row['trigger'] ?? '',
            ], $rows),
        );

        return $this->exitCodeFor($diagnostics);
    }

    private static function matchesStatusFilter(?int $status, string $filter): bool
    {
        if ($status === null) {
            return false;
        }
        if (ctype_digit($filter)) {
            return $status === (int)$filter;
        }
        if (preg_match('/^([1-5])xx$/i', $filter, $matches) === 1) {
            return intdiv($status, 100) === (int)$matches[1];
        }

        return false;
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
