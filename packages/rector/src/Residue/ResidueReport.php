<?php

declare(strict_types=1);

namespace Quiote\Rector\Residue;

/**
 * Collects the Context call sites no rewriting rule could handle, and writes them out once.
 *
 * A Rector rule has no reporting channel: it either changes a node or it does not. So the reporter
 * rule records into this, and this writes at process shutdown -- registered on the first recorded
 * site, so a run that finds nothing writes nothing rather than leaving an empty file that reads as a
 * clean result.
 *
 * **Appends, under a lock, one line per site.** Rector runs parallel worker processes by default, so
 * this class is instantiated once per worker and each worker's shutdown function fires separately.
 * Overwriting made the report one worker's partial view with last-writer-wins -- which looked exactly
 * like a rule that was not firing, and was misdiagnosed as such once already. Appending is what makes
 * the file the union of what every worker saw. It also means the file must be removed between runs;
 * stale lines from a previous run would otherwise read as current residue.
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
     * A call shaped exactly like a Context call whose receiver is something else -- an OpenTelemetry
     * span context, a Playwright browser context, a dashboard render context. Reported rather than
     * skipped: a rule correctly declining these is indistinguishable, in a report, from a file that
     * had no sites at all, and "did the rules miss this?" is the question the report exists to
     * answer. Nothing here is work to do; it is work confirmed not to be needed.
     */
    public const string REASON_FOREIGN_RECEIVER = 'foreign-receiver';

    /**
     * Shaped like a Context call, and the receiver's type cannot be resolved at all -- in practice an
     * untyped `$context = null` parameter, whose type is `mixed`. Distinct from `foreign-receiver` on
     * purpose: a foreign receiver is confirmed not to be work, while this is work that cannot be
     * decided without reading the call site. Letting the two share a label would hide a real site in
     * the bucket a reader skips.
     */
    public const string REASON_UNRESOLVED_RECEIVER = 'unresolved-receiver';

    /**
     * The receiver really is a Context, but the method is not one Context declares -- in practice a
     * PHPUnit mock builder on a mocked Context (`$context->method('getName')`), where the receiver
     * resolves to `MockObject&Context`. Named for the same reason as above.
     */
    public const string REASON_NOT_AN_ACCESSOR = 'not-an-accessor';

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

        $lines = '';
        foreach ($this->sites() as $site) {
            $lines .= sprintf("%s\t%d\t%s\t%s\n", $site['reason'], $site['line'], $site['accessor'], $site['file']);
        }

        if (@file_put_contents($path, $lines, FILE_APPEND | LOCK_EX) === false) {
            fwrite(STDERR, sprintf(
                '[ContextResidueReporter] could not write the residue report to "%s"; %d site(s) '
                . "were found and are not recorded anywhere.\n",
                $path,
                count($this->sites),
            ));
        }
    }
}
