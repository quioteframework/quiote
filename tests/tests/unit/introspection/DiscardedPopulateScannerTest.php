<?php

use Quiote\Introspection\DiscardedPopulateScanner;
use Quiote\Support\Compiler\Diagnostic;
use Quiote\Testing\UnitTestCase;

/**
 * The diagnostic for the Agavi prefill idiom that a port leaves behind: a statement that sets
 * `populate` on an immutable request and keeps nothing, so the form renders empty.
 */
final class DiscardedPopulateScannerTest extends UnitTestCase
{
    private string $directory = '';

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/quiote-discarded-populate-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/Shop/Views', 0777, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            if ($file instanceof SplFileInfo) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    private function write(string $relativePath, string $source): string
    {
        $path = $this->directory . '/' . $relativePath;
        file_put_contents($path, $source);
        return $path;
    }

    /** @return list<int|null> */
    private function reportedLines(string $source): array
    {
        $path = $this->write('Shop/Views/EditInputView.php', $source);
        return array_map(static fn(Diagnostic $d): ?int => $d->line, (new DiscardedPopulateScanner())->scanFile($path));
    }

    public function testReportsTheMigratedStatementOnItsLine(): void
    {
        $lines = $this->reportedLines(<<<'PHP'
            <?php
            class EditInputView
            {
                public function executeHtml($rd)
                {
                    $this->requestState->current()->setAttribute('populate', ['integrationForm' => $this->holder]);
                    return 'x';
                }
            }
            PHP);

        $this->assertSame([6], $lines);
    }

    public function testReportsTheAgaviFormWithItsNamespaceAndANullsafeOrStaticReceiver(): void
    {
        $lines = $this->reportedLines(<<<'PHP'
            <?php
            $this->getContext()->getRequest()->setAttribute("populate", ['f' => $h], 'org.agavi.filter.FormPopulationFilter');
            RequestState::current()?->setAttribute('populate', ['f' => $h]);
            if ($editing) {
                $request->setAttribute('populate', ['f' => [
                    'Name' => $record->getName(),
                ]]);
            }
            PHP);

        $this->assertSame([2, 3, 5], $lines);
    }

    public function testReportsAStatementInATemplateClosedByATag(): void
    {
        $this->assertSame([1], $this->reportedLines("<?php \$request->setAttribute('populate', ['f' => \$h]) ?>\n<form id=\"f\"></form>\n"));
    }

    public function testACallWhoseResultIsKeptIsNotReported(): void
    {
        $lines = $this->reportedLines(<<<'PHP'
            <?php
            $rd = $rd->setAttribute('populate', ['f' => $h]);
            return $rd->setAttribute('populate', ['f' => $h]);
            $requestState->publish($rd->setAttribute('populate', ['f' => $h]));
            $all = [$rd->setAttribute('populate', ['f' => $h])];
            $x = $flag ? $rd->setAttribute('populate', []) : $rd;
            PHP);

        $this->assertSame([], $lines);
    }

    public function testAnotherAttributeOrMethodIsNotReported(): void
    {
        $lines = $this->reportedLines(<<<'PHP'
            <?php
            $rd->setAttribute('populated', true);
            $rd->setAttribute($name, ['f' => $h]);
            $rd->withAttribute('populate', ['f' => $h]);
            setAttribute('populate', []);
            // $rd->setAttribute('populate', []);
            PHP);

        $this->assertSame([], $lines);
    }

    public function testScansEveryPhpFileUnderTheGivenDirectoriesAndSkipsTheRest(): void
    {
        $this->write('Shop/Views/EditInputView.php', "<?php\n\$rd->setAttribute('populate', []);\n");
        $this->write('Shop/Views/notes.txt', "\$rd->setAttribute('populate', []);\n");
        mkdir($this->directory . '/Shop/Templates');
        $template = $this->write('Shop/Templates/EditInput.php', "<?php \$rd->setAttribute('populate', []); ?>\n");

        $diagnostics = (new DiscardedPopulateScanner())->scan([$this->directory, $this->directory . '/does-not-exist']);

        $files = array_map(static fn(Diagnostic $d): string => $d->where, $diagnostics);
        sort($files);
        $this->assertSame([$template, $this->directory . '/Shop/Views/EditInputView.php'], $files);
        foreach ($diagnostics as $diagnostic) {
            $this->assertSame(Diagnostic::CODE_DISCARDED_POPULATE, $diagnostic->code);
            $this->assertSame(Diagnostic::SEVERITY_WARNING, $diagnostic->severity);
            $this->assertStringContainsString('populateForm(', $diagnostic->message);
        }
    }
}
