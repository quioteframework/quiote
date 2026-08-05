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

        // Keyed on the fixture, because each one is about a different set of answers and the report is
        // per-run: asserting the union would pass for a fixture that recorded nothing.
        match (basename($filePath)) {
            'reports_template_scope.php.inc' => $this->assertTemplateScope($reasons),
            default => $this->assertEveryResidueShape($reasons),
        };
    }

    /**
     * @param      array<string, string> $reasons
     */
    private function assertEveryResidueShape(array $reasons): void
    {
        $this->assertSame(ResidueReport::REASON_NOT_CONTAINER_BUILT, $reasons['getUser'] ?? null);
        // Shaped like a Context call, receiver is definitely something else: named, not silently
        // skipped. The accessor recorded is the one called *on* the lookalike receiver, which is what
        // a reader needs to recognise the site.
        $this->assertSame(ResidueReport::REASON_FOREIGN_RECEIVER, $reasons['getMessage'] ?? null);
        // A real Context, a method it does not declare.
        $this->assertSame(ResidueReport::REASON_NOT_AN_ACCESSOR, $reasons['expects'] ?? null);
        // A method Context still declares is never residue, whatever class it is called in -- there
        // would be nothing for a reader to decide, and the destination of the migration cannot be on
        // the list of work remaining.
        $this->assertArrayNotHasKey('getContainer', $reasons);
        $this->assertArrayNotHasKey('get', $reasons);
        // An untyped $context = null parameter: unresolvable rather than foreign, which is a different
        // answer for whoever works the list.
        $this->assertSame(ResidueReport::REASON_UNRESOLVED_RECEIVER, $reasons['getRouting'] ?? null);
    }

    /**
     * A file with no class in it is where both the rules and this reporter used to see nothing at all.
     *
     * @param      array<string, string> $reasons
     */
    private function assertTemplateScope(array $reasons): void
    {
        $this->assertSame(ResidueReport::REASON_NO_CLASS, $reasons['getTranslationManager'] ?? null);
        $this->assertSame(ResidueReport::REASON_NO_CLASS, $reasons['getService'] ?? null);
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
