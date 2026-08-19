<?php

declare(strict_types=1);

namespace Quiote\Replay\Console;

use Quiote\Config\Config;
use Quiote\Console\Command\AbstractAppCommand;
use Quiote\Replay\Cassette\CassetteId;
use Quiote\Replay\Store\FileCassetteStore;
use Quiote\Support\Compiler\Diagnostic;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * `cassette:list` -- enumerates the configured store (the file store's own
 * directory listing today; a non-file store gains this once it exists, per
 * `docs/RECORD_REPLAY_PLAN.md` §12.8).
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

        $store = $this->resolveFileStore($io);
        if ($store === null) {
            return self::FAILURE;
        }

        [$rows, $diagnostics] = $this->collectRows($store);

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

    /**
     * @return array{0: list<array{id: string, recorded_at: ?string, route: ?string, status: ?int, trigger: ?string}>, 1: list<Diagnostic>}
     */
    private function collectRows(FileCassetteStore $store): array
    {
        $rows = [];
        $diagnostics = [];
        foreach ($store->slugs() as $slug) {
            try {
                $cassette = $store->get(CassetteId::fromRaw($slug));
            } catch (Throwable $e) {
                $diagnostics[] = new Diagnostic(Diagnostic::SEVERITY_WARNING, 'CASSETTE_UNREADABLE', $e->getMessage(), $slug);
                continue;
            }
            if ($cassette === null) {
                continue;
            }
            $id = $cassette->meta['id'] ?? null;
            $recordedAt = $cassette->meta['recorded_at'] ?? null;
            $route = $cassette->resolved['route'] ?? null;
            $status = $cassette->response['status'] ?? null;
            $trigger = $cassette->meta['trigger'] ?? null;
            $rows[] = [
                'id' => is_string($id) ? $id : $slug,
                'recorded_at' => is_string($recordedAt) ? $recordedAt : null,
                'route' => is_string($route) ? $route : null,
                'status' => is_int($status) ? $status : null,
                'trigger' => is_string($trigger) ? $trigger : null,
            ];
        }

        return [$rows, $diagnostics];
    }

    private function resolveFileStore(SymfonyStyle $io): ?FileCassetteStore
    {
        $storeAlias = Config::getString('replay.store', 'file');
        if ($storeAlias !== 'file') {
            $io->error(sprintf('cassette:list only supports the "file" store today; "replay.store" is "%s".', $storeAlias));

            return null;
        }
        try {
            return new FileCassetteStore(Config::getString('replay.store.path', 'var/cassettes'));
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return null;
        }
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
