<?php

declare(strict_types=1);

namespace Quiote\Rector\Tests\Rector\ContextResidueReporter;

use PHPUnit\Framework\Attributes\DataProvider;
use Quiote\Rector\Residue\ResidueReport;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;

/**
 * The reporter rewrites nothing, so a fixture with no expected-output half proves exactly the right
 * thing: the file comes back unchanged. What it *records* is then read from the same ResidueReport
 * instance the rule was handed, so the assertions are about the categories rather than about the
 * report's formatting -- which {@see \Quiote\Rector\Tests\Residue\ResidueReportTest} covers.
 */
final class ContextResidueReporterTest extends AbstractRectorTestCase
{
    #[DataProvider('provideData')]
    public function test(string $filePath): void
    {
        $this->doTestFile($filePath);

        $report = self::getContainer()->make(ResidueReport::class);
        $this->assertInstanceOf(ResidueReport::class, $report);

        $reasons = [];
        foreach ($report->sites() as $site) {
            $reasons[$site['accessor']] = $site['reason'];
        }

        $this->assertSame(ResidueReport::REASON_NOT_CONTAINER_BUILT, $reasons['getUser'] ?? null);
        // Shaped like a Context call, receiver is a span: named, not silently skipped. The accessor
        // recorded is the one called *on* the lookalike receiver, which is what a reader needs to
        // recognise the site.
        $this->assertSame(ResidueReport::REASON_FOREIGN_RECEIVER, $reasons['isValid'] ?? null);
        // A real Context, a method it does not declare.
        $this->assertSame(ResidueReport::REASON_NOT_AN_ACCESSOR, $reasons['flushRequestState'] ?? null);
    }

    /** @return \Iterator<array<string>> */
    public static function provideData(): \Iterator
    {
        return self::yieldFilesFromDirectory(__DIR__ . '/Fixture');
    }

    public function provideConfigFilePath(): string
    {
        return __DIR__ . '/config/configured_rule.php';
    }
}
