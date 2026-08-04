<?php

declare(strict_types=1);

namespace Quiote\Rector\Tests\Residue;

use Quiote\Rector\Residue\ResidueReport;
use Quiote\Testing\PhpUnitTestCase;

/**
 * The collector is deliberately separable from the rule, so the part that decides what a report says
 * is testable without Rector's container.
 */
final class ResidueReportTest extends PhpUnitTestCase
{
    public function testAFreshReportHasNoSites(): void
    {
        $report = new ResidueReport();

        $this->assertSame([], $report->sites());
        $this->assertSame([], $report->countsByReason());
    }

    public function testSitesAreRecordedWithTheirReason(): void
    {
        $report = new ResidueReport();
        $report->add('/app/Foo.php', 12, 'getUser', ResidueReport::REASON_UNHANDLED);

        $this->assertSame(
            [['file' => '/app/Foo.php', 'line' => 12, 'accessor' => 'getUser', 'reason' => ResidueReport::REASON_UNHANDLED]],
            $report->sites(),
        );
    }

    /**
     * The reporter and a rewriting rule can both see the same site in one run. Counting it twice
     * would overstate the remaining work, which is the one number the report exists to give.
     */
    public function testTheSameSiteIsNotCountedTwice(): void
    {
        $report = new ResidueReport();
        $report->add('/app/Foo.php', 12, 'getUser', ResidueReport::REASON_UNHANDLED);
        $report->add('/app/Foo.php', 12, 'getUser', ResidueReport::REASON_UNHANDLED);

        $this->assertCount(1, $report->sites());
    }

    public function testDistinctSitesOnOneLineAreKept(): void
    {
        $report = new ResidueReport();
        $report->add('/app/Foo.php', 12, 'getUser', ResidueReport::REASON_UNHANDLED);
        $report->add('/app/Foo.php', 12, 'getRouting', ResidueReport::REASON_NOT_CONTAINER_BUILT);

        $this->assertCount(2, $report->sites(), 'a chained line can hold two distinct accessors');
    }

    /**
     * Most numerous first: a whole category usually has one answer, so that is the order the work
     * should be planned in.
     */
    public function testCountsByReasonAreOrderedMostNumerousFirst(): void
    {
        $report = new ResidueReport();
        $report->add('/app/A.php', 1, 'getUser', ResidueReport::REASON_UNHANDLED);
        $report->add('/app/B.php', 1, 'getRouting', ResidueReport::REASON_NOT_CONTAINER_BUILT);
        $report->add('/app/C.php', 1, 'getRouting', ResidueReport::REASON_NOT_CONTAINER_BUILT);

        $this->assertSame(
            [ResidueReport::REASON_NOT_CONTAINER_BUILT => 2, ResidueReport::REASON_UNHANDLED => 1],
            $report->countsByReason(),
        );
    }

    public function testTheRenderedReportSummarisesThenListsGroupedByReason(): void
    {
        $report = new ResidueReport();
        $report->add('/app/B.php', 20, 'getRouting', ResidueReport::REASON_NOT_CONTAINER_BUILT);
        $report->add('/app/A.php', 10, 'getUser', ResidueReport::REASON_UNHANDLED);

        $rendered = $report->render();

        $this->assertStringContainsString('2 site(s)', $rendered);
        $this->assertStringContainsString('## ' . ResidueReport::REASON_NOT_CONTAINER_BUILT, $rendered);
        $this->assertStringContainsString('/app/B.php:20  getRouting', $rendered);
        $this->assertStringContainsString('/app/A.php:10  getUser', $rendered);
    }

    /**
     * A run that finds nothing must leave no file. An empty report on disk reads as a clean result,
     * which is exactly the confusion this whole reporter exists to prevent.
     */
    public function testWritingAnEmptyReportProducesNoFile(): void
    {
        $path = sys_get_temp_dir() . '/quiote-residue-empty-' . getmypid() . '.txt';
        @unlink($path);
        putenv('QUIOTE_RECTOR_RESIDUE=' . $path);

        try {
            (new ResidueReport())->write();
            $this->assertFileDoesNotExist($path);
        } finally {
            putenv('QUIOTE_RECTOR_RESIDUE');
            @unlink($path);
        }
    }

    public function testWriteGoesToTheConfiguredPath(): void
    {
        $path = sys_get_temp_dir() . '/quiote-residue-' . getmypid() . '.txt';
        @unlink($path);
        putenv('QUIOTE_RECTOR_RESIDUE=' . $path);

        try {
            $report = new ResidueReport();
            $report->add('/app/Foo.php', 7, 'getUser', ResidueReport::REASON_UNHANDLED);
            $report->write();

            $this->assertFileExists($path);
            $this->assertStringContainsString('/app/Foo.php:7  getUser', (string) file_get_contents($path));
        } finally {
            putenv('QUIOTE_RECTOR_RESIDUE');
            @unlink($path);
        }
    }
}
