<?php

declare(strict_types=1);

namespace Quiote\Rector\Residue;

/**
 * Collects the Context call sites no rewriting rule could handle, and writes them out once.
 *
 * A Rector rule has no reporting channel: it either changes a node or it does not. So the reporter
 * rule records into this, and this writes a file at process shutdown -- registered on the first
 * recorded site, so a run that finds nothing writes nothing rather than leaving an empty file that
 * reads as a clean result.
 *
 * Kept separate from the rule so the collection is testable without Rector's container, and so the
 * rule stays about recognising sites rather than about formatting and file handling.
 *
 * @since      4.0.0
 */
final class ResidueReport
{
    public const string REASON_NOT_CONTAINER_BUILT = 'not-container-built';
    public const string REASON_NULLABLE_ACCESSOR = 'nullable-accessor';
    public const string REASON_DISCARDED_MUTATION = 'discarded-mutation';
    public const string REASON_UNRESOLVABLE_ARGUMENT = 'unresolvable-argument';
    public const string REASON_UNHANDLED = 'unhandled-accessor';

    /**
     * @var        array<string, array{file: string, line: int, accessor: string, reason: string}>
     *             Keyed by file:line:accessor, so a rule and the reporter seeing the same site once
     *             each does not double-count it.
     */
    private array $sites = [];

    private bool $flushRegistered = false;

    public function add(string $file, int $line, string $accessor, string $reason): void
    {
        $this->sites[$file . ':' . $line . ':' . $accessor] = [
            'file' => $file,
            'line' => $line,
            'accessor' => $accessor,
            'reason' => $reason,
        ];

        if (!$this->flushRegistered) {
            $this->flushRegistered = true;
            register_shutdown_function(function (): void {
                $this->write();
            });
        }
    }

    /**
     * @return     array<int, array{file: string, line: int, accessor: string, reason: string}>
     * @since      4.0.0
     */
    public function sites(): array
    {
        return array_values($this->sites);
    }

    /**
     * Sites grouped by reason, most numerous first -- which is the order the work should be planned
     * in, since a whole category usually has one answer.
     *
     * @return     array<string, int>
     * @since      4.0.0
     */
    public function countsByReason(): array
    {
        $counts = [];
        foreach ($this->sites as $site) {
            $counts[$site['reason']] = ($counts[$site['reason']] ?? 0) + 1;
        }
        arsort($counts);

        return $counts;
    }

    /**
     * The report as text: a summary by reason, then every site.
     *
     * @since      4.0.0
     */
    public function render(): string
    {
        $lines = ['# Context decomposition residue', ''];
        $lines[] = sprintf('%d site(s) no rewriting rule could handle.', count($this->sites));
        $lines[] = '';

        foreach ($this->countsByReason() as $reason => $count) {
            $lines[] = sprintf('  %-24s %d', $reason, $count);
        }
        $lines[] = '';

        $sites = $this->sites();
        usort($sites, static fn(array $a, array $b): int
            => [$a['reason'], $a['file'], $a['line']] <=> [$b['reason'], $b['file'], $b['line']]);

        $reason = null;
        foreach ($sites as $site) {
            if ($site['reason'] !== $reason) {
                $reason = $site['reason'];
                $lines[] = '';
                $lines[] = '## ' . $reason;
            }
            $lines[] = sprintf('%s:%d  %s', $site['file'], $site['line'], $site['accessor']);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Write the report, to `QUIOTE_RECTOR_RESIDUE` or the working directory.
     *
     * Failure to write is reported to STDERR rather than thrown: this runs at shutdown, after
     * Rector has finished, and throwing here would turn a successful run into a confusing one.
     *
     * @since      4.0.0
     */
    public function write(): void
    {
        if ($this->sites === []) {
            return;
        }

        $path = getenv('QUIOTE_RECTOR_RESIDUE');
        if (!is_string($path) || $path === '') {
            $path = getcwd() . '/context-residue.txt';
        }

        if (@file_put_contents($path, $this->render()) === false) {
            fwrite(STDERR, sprintf(
                '[ContextResidueReporter] could not write the residue report to "%s"; %d site(s) '
                . "were found and are not recorded anywhere.\n",
                $path,
                count($this->sites),
            ));
        }
    }
}
