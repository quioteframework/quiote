<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\TestCase;
use Quiote\Docs\Scan\ScannedType;
use Quiote\Docs\Scan\SourceScanner;
use Quiote\Support\Compiler\Diagnostic;

/**
 * Covers discovery against fixtures reproducing each hazard the framework tree contains:
 * a file declaring several class-likes with the addressable one last, one base directory
 * reachable from two PSR-4 prefixes, and a tombstone file that declares nothing.
 */
final class SourceScannerTest extends TestCase
{
    private string $fixtures;

    protected function setUp(): void
    {
        $this->fixtures = __DIR__ . '/fixtures';
    }

    private function loader(): ClassLoader
    {
        $loader = new ClassLoader();
        $loader->addPsr4('Quiote\\Fixture\\Api\\', $this->fixtures . '/src');

        return $loader;
    }

    /**
     * @param list<ScannedType> $types
     * @return list<string>
     */
    private function names(array $types): array
    {
        return array_map(static fn(ScannedType $t): string => $t->fqcn, $types);
    }

    public function testScanIsEmptyWhenNoPrefixIsUnderTheFrameworkNamespace(): void
    {
        $loader = new ClassLoader();
        $loader->addPsr4('Elsewhere\\', $this->fixtures . '/src');

        $scanner = new SourceScanner($loader, excludeTestDirectories: false);

        $this->assertSame([], $scanner->scan());
        $this->assertSame([], $scanner->roots());
    }

    public function testDiscoversTypesAndOrdersThemByName(): void
    {
        $scanner = new SourceScanner($this->loader(), excludeTestDirectories: false);
        $names = $this->names($scanner->scan());

        $sorted = $names;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $names, 'output order must not depend on the filesystem');
        $this->assertContains('Quiote\Fixture\Api\Plain', $names);
        $this->assertContains('Quiote\Fixture\Api\Sub\Nested', $names);
    }

    public function testCompanionTypesInTheSameFileAreDiscoveredAlongsideTheAddressableOne(): void
    {
        $scanner = new SourceScanner($this->loader(), excludeTestDirectories: false);
        $names = $this->names($scanner->scan());

        // Companion.php declares the interface and enum before the class the path names.
        $this->assertContains('Quiote\Fixture\Api\Companion', $names);
        $this->assertContains('Quiote\Fixture\Api\CompanionProblem', $names);
        $this->assertContains('Quiote\Fixture\Api\CompanionKind', $names);
    }

    public function testRecordsTheDeclaredKindOfEachType(): void
    {
        $scanner = new SourceScanner($this->loader(), excludeTestDirectories: false);
        $kinds = [];
        foreach ($scanner->scan() as $type) {
            $kinds[$type->fqcn] = $type->kind;
        }

        $this->assertSame('class', $kinds['Quiote\Fixture\Api\Plain']);
        $this->assertSame('interface', $kinds['Quiote\Fixture\Api\CompanionProblem']);
        $this->assertSame('enum', $kinds['Quiote\Fixture\Api\CompanionKind']);
        $this->assertSame('trait', $kinds['Quiote\Fixture\Api\Sub\Nested']);
    }

    public function testTombstoneFileIsSkippedWithoutADiagnostic(): void
    {
        $scanner = new SourceScanner($this->loader(), excludeTestDirectories: false);
        $names = $this->names($scanner->scan());

        $this->assertNotContains('Quiote\Fixture\Api\Removed', $names);
        $this->assertSame([], $scanner->getDiagnostics(), 'a removed-class tombstone is not a defect');
    }

    public function testAnonymousClassesAndClassConstantsAreNotMistakenForDeclarations(): void
    {
        $scanner = new SourceScanner($this->loader(), excludeTestDirectories: false);

        foreach ($this->names($scanner->scan()) as $name) {
            $this->assertStringNotContainsString('@', $name);
        }
        // Plain.php contains `Collaborator::class` and `new class { … }`; neither is addressable.
        $this->assertSame(
            ['Quiote\Fixture\Api\Plain'],
            array_values(array_filter(
                $this->names($scanner->scan()),
                static fn(string $n): bool => str_starts_with($n, 'Quiote\Fixture\Api\Plain'),
            )),
        );
    }

    /**
     * The framework's own `packages/session-pdo/src` is reachable as both `Quiote\Session\Pdo\`
     * and `Quiote\Storage\Pdo\`. Only one names what the files declare; resolving the other would
     * include the file a second time and raise an uncatchable redeclaration fatal.
     */
    public function testOneDirectoryUnderTwoPrefixesResolvesUnderTheMatchingOneOnly(): void
    {
        $loader = new ClassLoader();
        $loader->addPsr4('Quiote\\Real\\', $this->fixtures . '/shared');
        $loader->addPsr4('Quiote\\Phantom\\', $this->fixtures . '/shared');

        $scanner = new SourceScanner($loader, excludeTestDirectories: false);
        $names = $this->names($scanner->scan());

        $this->assertSame(['Quiote\Real\Shared'], $names);
        $this->assertNotContains('Quiote\Phantom\Shared', $names);
        $this->assertSame(
            [],
            $scanner->getDiagnostics(),
            'the file resolved under another prefix, so the mismatch is not worth reporting',
        );
    }

    public function testAFileNoPrefixCanAddressIsReportedOnce(): void
    {
        $loader = new ClassLoader();
        $loader->addPsr4('Quiote\\Phantom\\', $this->fixtures . '/shared');

        $scanner = new SourceScanner($loader, excludeTestDirectories: false);

        $this->assertSame([], $scanner->scan());

        $diagnostics = $scanner->getDiagnostics();
        $this->assertCount(1, $diagnostics);
        $this->assertSame(Diagnostic::SEVERITY_WARNING, $diagnostics[0]->severity);
        $this->assertSame(Diagnostic::CODE_UNRESOLVABLE_CLASS, $diagnostics[0]->code);
        $this->assertStringContainsString('Shared.php', $diagnostics[0]->where);
    }

    public function testDirectoriesUnderATestsSegmentAreExcluded(): void
    {
        $loader = new ClassLoader();
        $loader->addPsr4('Quiote\\Fixture\\', __DIR__);

        $scanner = new SourceScanner($loader);

        $this->assertSame([], $scanner->roots(), 'this file lives under tests/, so its tree is not API');
    }

    public function testRootsAreOrderedLongestPrefixFirstThenByName(): void
    {
        $loader = new ClassLoader();
        $loader->addPsr4('Quiote\\', $this->fixtures . '/src');
        $loader->addPsr4('Quiote\\Beta\\Deep\\', $this->fixtures . '/src');
        $loader->addPsr4('Quiote\\Alpha\\Deep\\', $this->fixtures . '/src');

        $prefixes = array_column(
            (new SourceScanner($loader, excludeTestDirectories: false))->roots(),
            'prefix',
        );

        $this->assertSame(['Quiote\Alpha\Deep\\', 'Quiote\Beta\Deep\\', 'Quiote\\'], $prefixes);
    }

    public function testRelativePathIsResolvedAgainstTheBaseDirectory(): void
    {
        $scanner = new SourceScanner($this->loader(), excludeTestDirectories: false);

        foreach ($scanner->scan() as $type) {
            if ($type->fqcn === 'Quiote\Fixture\Api\Sub\Nested') {
                $this->assertSame('Sub/Nested.php', $type->relativePath());
                return;
            }
        }

        $this->fail('the nested fixture was not discovered');
    }

    public function testImportsAreCollectedForDocblockReferenceResolution(): void
    {
        $scanner = new SourceScanner($this->loader(), excludeTestDirectories: false);

        foreach ($scanner->scan() as $type) {
            if ($type->fqcn !== 'Quiote\Fixture\Api\Plain') {
                continue;
            }

            $this->assertSame('Fixture\Other\Collaborator', $type->resolveImport('Collaborator'));
            $this->assertSame('Fixture\Other\Renamed', $type->resolveImport('Alias'));
            $this->assertSame('Fixture\Group\First', $type->resolveImport('First'));
            $this->assertSame('Fixture\Group\Second', $type->resolveImport('Deux'));
            $this->assertSame('Fixture\Other\Collaborator', $type->resolveImport('collaborator'), 'aliases are case-insensitive');
            $this->assertNull($type->resolveImport('Missing'));

            // `use function`/`use const` name symbols, and `use SomeTrait;` inside the class body
            // pulls in a trait; none of them is a type import a docblock could reference.
            $this->assertNull($type->resolveImport('helper'));
            $this->assertNull($type->resolveImport('LIMIT'));
            $this->assertNull($type->resolveImport('SomeTrait'));
            return;
        }

        $this->fail('the Plain fixture was not discovered');
    }

    public function testScanningTwiceProducesIdenticalResults(): void
    {
        $scanner = new SourceScanner($this->loader(), excludeTestDirectories: false);

        $this->assertSame($this->names($scanner->scan()), $this->names($scanner->scan()));
    }
}
