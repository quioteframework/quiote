<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Docs\DocsGenerator;
use Quiote\Docs\Ir\ApiIndex;
use Quiote\Docs\Ir\ClassDoc;
use Quiote\Docs\Ir\DocBlock;
use Quiote\Docs\Ir\MethodDoc;
use Quiote\Docs\Ir\ParamDoc;
use Quiote\Docs\Ir\TypeRef;
use Quiote\Support\Compiler\Diagnostic;

/**
 * Covers page generation from a model built by hand.
 *
 * Nothing here reflects anything, which is the point: the emitters are meant to be a pure
 * function of the model, so their output can be asserted on without a framework to read.
 */
final class DocsGeneratorTest extends TestCase
{
    private function method(string $name, string $summary = ''): MethodDoc
    {
        return new MethodDoc(
            name: $name,
            parameters: [
                new ParamDoc(
                    name: 'key',
                    type: TypeRef::literal('string'),
                    description: 'The key to record.',
                ),
            ],
            returnType: TypeRef::literal('void'),
            doc: new DocBlock(summary: $summary),
        );
    }

    /**
     * @param 'class'|'interface'|'trait'|'enum' $kind
     * @param list<MethodDoc> $methods
     */
    private function classDoc(
        string $fqcn,
        string $namespace,
        string $kind = 'class',
        ?DocBlock $doc = null,
        array $methods = [],
    ): ClassDoc {
        $short = substr($fqcn, (int) strrpos($fqcn, '\\') + 1);

        return new ClassDoc(
            fqcn: $fqcn,
            shortName: $short,
            namespace: $namespace,
            kind: $kind,
            doc: $doc ?? new DocBlock(summary: 'A ' . $short . '.'),
            sourcePath: str_replace('\\', '/', $short) . '.php',
            final: true,
            methods: $methods,
        );
    }

    private function index(ClassDoc ...$classes): ApiIndex
    {
        return new ApiIndex(array_values($classes));
    }

    public function testEmitsALandingPageANamespaceIndexAndAPagePerClass(): void
    {
        $index = $this->index(
            $this->classDoc('Quiote\Execution\SlotStack', 'Quiote\Execution'),
            $this->classDoc('Quiote\Execution\SlotContent', 'Quiote\Execution'),
        );

        $artifacts = (new DocsGenerator())->generate($index);

        $this->assertArrayHasKey('index.md', $artifacts);
        $this->assertArrayHasKey('execution/index.md', $artifacts);
        $this->assertArrayHasKey('execution/slot-stack.md', $artifacts);
        $this->assertArrayHasKey('execution/slot-content.md', $artifacts);
        $this->assertArrayHasKey(DocsGenerator::MANIFEST_FILE, $artifacts);
    }

    public function testAPageCarriesStarlightFrontmatterAndStartsItsBodyAtHeadingTwo(): void
    {
        $index = $this->index($this->classDoc('Quiote\Execution\SlotStack', 'Quiote\Execution'));

        $page = (new DocsGenerator())->generate($index)['execution/slot-stack.md']->phpSource;

        $this->assertStringStartsWith("---\ntitle: \"SlotStack\"\n", $page);
        $this->assertStringContainsString('description: "A SlotStack."', $page);
        $this->assertStringNotContainsString("\n# ", $page, 'the H1 comes from the frontmatter title');
        $this->assertStringContainsString("\n## Synopsis\n", $page);
    }

    public function testFrontmatterSurvivesProseThatWouldBreakYaml(): void
    {
        $index = $this->index($this->classDoc(
            'Quiote\Execution\SlotStack',
            'Quiote\Execution',
            doc: new DocBlock(summary: 'Handles "quoted" text: colons, and a \\ backslash.'),
        ));

        $page = (new DocsGenerator())->generate($index)['execution/slot-stack.md']->phpSource;

        $this->assertStringContainsString(
            'description: "Handles \\"quoted\\" text: colons, and a \\\\ backslash."',
            $page,
        );
    }

    public function testAMethodIsListedInTheTableAndDocumentedBelowIt(): void
    {
        $index = $this->index($this->classDoc(
            'Quiote\Execution\SlotStack',
            'Quiote\Execution',
            methods: [$this->method('markWarned', 'Records that a warning was emitted.')],
        ));

        $page = (new DocsGenerator())->generate($index)['execution/slot-stack.md']->phpSource;

        $this->assertStringContainsString('[`markWarned(string $key): void`](#markwarned)', $page);
        $this->assertStringContainsString("### markWarned()\n", $page);
        $this->assertStringContainsString('`public function markWarned(string $key): void`', $page);
        $this->assertStringContainsString('| `$key` | `string` | The key to record. |', $page);
    }

    public function testATypeInsideTheReferenceIsLinkedAndOneOutsideItIsNot(): void
    {
        $documented = $this->classDoc('Quiote\Execution\SlotContent', 'Quiote\Execution');
        $subject = new ClassDoc(
            fqcn: 'Quiote\Execution\SlotStack',
            shortName: 'SlotStack',
            namespace: 'Quiote\Execution',
            kind: 'class',
            doc: new DocBlock(summary: 'Holds slots.'),
            sourcePath: 'Execution/SlotStack.php',
            interfaces: [
                TypeRef::named('Quiote\Execution\SlotContent'),
                TypeRef::named('Vendor\Unknown\Thing'),
            ],
        );

        $page = (new DocsGenerator())->generate($this->index($documented, $subject))['execution/slot-stack.md']->phpSource;

        $this->assertStringContainsString('[`SlotContent`](/api/execution/slot-content/)', $page);
        $this->assertStringContainsString('`Thing`', $page);
        $this->assertStringNotContainsString('](/api/vendor', $page);
    }

    public function testTheManifestRecordsEveryPageSoADeletionCanBeSeen(): void
    {
        $index = $this->index($this->classDoc('Quiote\Execution\SlotStack', 'Quiote\Execution'));

        $artifacts = (new DocsGenerator())->generate($index);
        $manifest = json_decode($artifacts[DocsGenerator::MANIFEST_FILE]->phpSource, true);

        $this->assertIsArray($manifest);
        $this->assertSame(1, $manifest['schema']);
        $this->assertSame(1, $manifest['types']);

        $files = $manifest['files'];
        $this->assertIsArray($files);
        $this->assertArrayHasKey('execution/slot-stack.md', $files);
        $this->assertSame(
            $artifacts['execution/slot-stack.md']->checksum,
            $files['execution/slot-stack.md'],
        );
        $this->assertArrayNotHasKey(
            DocsGenerator::MANIFEST_FILE,
            $files,
            'the manifest cannot contain its own checksum',
        );
    }

    public function testTheManifestCarriesNoVersionOrTimestamp(): void
    {
        $index = $this->index($this->classDoc('Quiote\Execution\SlotStack', 'Quiote\Execution'));

        $manifest = (new DocsGenerator())->generate($index)[DocsGenerator::MANIFEST_FILE]->phpSource;

        // Either would rewrite every page on every run and make drift checking meaningless.
        $this->assertStringNotContainsString('generated', $manifest);
        $this->assertStringNotContainsString('version', $manifest);
        $this->assertDoesNotMatchRegularExpression('/\d{4}-\d{2}-\d{2}/', $manifest);
    }

    public function testGeneratingTwiceProducesIdenticalChecksums(): void
    {
        $index = $this->index(
            $this->classDoc('Quiote\Execution\SlotStack', 'Quiote\Execution', methods: [$this->method('push')]),
            $this->classDoc('Quiote\Http\SimpleUri', 'Quiote\Http'),
        );

        $first = (new DocsGenerator())->generate($index);
        $second = (new DocsGenerator())->generate($index);

        $this->assertSame(array_keys($first), array_keys($second));
        foreach ($first as $target => $artifact) {
            $this->assertSame($artifact->checksum, $second[$target]->checksum, $target);
        }
    }

    /**
     * A class page and a namespace index that resolve to one path would become a duplicate
     * route. The site is built where no PHP runs, so this has to be caught here.
     */
    public function testAClassCollidingWithANamespaceIndexIsReportedAsAnError(): void
    {
        $generator = new DocsGenerator();

        $index = $this->index(
            // Quiote\Execution\Slot would be written to execution/slot.md, and the namespace
            // Quiote\Execution\Slot writes execution/slot/index.md -- both serve /api/execution/slot/.
            $this->classDoc('Quiote\Execution\Slot', 'Quiote\Execution'),
            $this->classDoc('Quiote\Execution\Slot\Inner', 'Quiote\Execution\Slot'),
        );

        $generator->generate($index);
        $diagnostics = $generator->getDiagnostics();

        $this->assertCount(1, $diagnostics);
        $this->assertSame(Diagnostic::SEVERITY_ERROR, $diagnostics[0]->severity);
        $this->assertStringContainsString('execution/slot', $diagnostics[0]->message);
        $this->assertSame('Quiote\Execution\Slot', $diagnostics[0]->symbol);
    }

    public function testAnIntermediateNamespaceWithNoTypesOfItsOwnStillGetsAPage(): void
    {
        $index = $this->index($this->classDoc('Quiote\Config\Util\DOM\Attr', 'Quiote\Config\Util\DOM'));

        $artifacts = (new DocsGenerator())->generate($index);

        // Nothing lives in Quiote\Config\Util, but the level above links down through it.
        $this->assertArrayHasKey('config/util/index.md', $artifacts);
        $this->assertArrayHasKey('config/util/dom/index.md', $artifacts);
    }

    public function testTheRootNamespaceHasNoIndexOfItsOwnBecauseTheLandingPageIsIt(): void
    {
        $index = $this->index($this->classDoc('Quiote\Context', 'Quiote'));

        $artifacts = (new DocsGenerator())->generate($index);

        $this->assertArrayHasKey('context.md', $artifacts);
        $this->assertArrayNotHasKey('quiote/index.md', $artifacts);
        $this->assertStringContainsString('## At the root', $artifacts['index.md']->phpSource);
        $this->assertStringContainsString('[`Context`](/api/context/)', $artifacts['index.md']->phpSource);
    }

    public function testEveryPageEndsWithExactlyOneNewline(): void
    {
        $index = $this->index($this->classDoc('Quiote\Execution\SlotStack', 'Quiote\Execution'));

        foreach ((new DocsGenerator())->generate($index) as $target => $artifact) {
            $this->assertStringEndsWith("\n", $artifact->phpSource, $target);
            $this->assertStringEndsNotWith("\n\n", $artifact->phpSource, $target);
            $this->assertStringNotContainsString("\r", $artifact->phpSource, $target);
        }
    }
}
