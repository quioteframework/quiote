<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Docs\Docblock\DocblockParser;

final class DocblockParserTest extends TestCase
{
    private DocblockParser $parser;

    protected function setUp(): void
    {
        $this->parser = new DocblockParser();
    }

    public function testAMissingDocblockIsSimplyEmpty(): void
    {
        $doc = $this->parser->parse(null);

        $this->assertTrue($doc->isEmpty());
        $this->assertSame('', $doc->summary);
    }

    public function testTheFirstSentenceBecomesTheSummaryAndTheRestTheDescription(): void
    {
        $doc = $this->parser->parse(<<<'DOC'
            /**
             * Returns the recorded request. This trailing clause belongs to the description.
             *
             * A second paragraph.
             */
            DOC);

        $this->assertSame('Returns the recorded request.', $doc->summary);
        $this->assertStringContainsString('This trailing clause belongs to the description.', $doc->description);
        $this->assertStringContainsString('A second paragraph.', $doc->description);
    }

    public function testAWrappedParagraphIsRejoinedOntoOneLine(): void
    {
        $doc = $this->parser->parse(<<<'DOC'
            /**
             * A summary split
             * across two source lines.
             */
            DOC);

        $this->assertSame('A summary split across two source lines.', $doc->summary);
    }

    public function testParameterTypesAndDescriptionsAreCaptured(): void
    {
        $doc = $this->parser->parse(<<<'DOC'
            /**
             * Does a thing.
             *
             * @param list<Route> $routes The routes to consider.
             * @param int $limit
             */
            DOC);

        $this->assertSame('list<Route>', $doc->paramTypes['routes']);
        $this->assertSame('The routes to consider.', $doc->paramDescriptions['routes']);
        $this->assertSame('int', $doc->paramTypes['limit']);
        $this->assertArrayNotHasKey('limit', $doc->paramDescriptions);
    }

    public function testTheReturnTypeIsTakenFromTheDocblockBecauseItIsTheNarrowerOne(): void
    {
        $doc = $this->parser->parse(<<<'DOC'
            /**
             * Lists them.
             *
             * @return array<string, Route> keyed by name
             */
            DOC);

        $this->assertSame('array<string, Route>', $doc->returnType);
        $this->assertSame('keyed by name', $doc->returnDescription);
    }

    public function testThrowsBecomesItsOwnList(): void
    {
        $doc = $this->parser->parse(<<<'DOC'
            /**
             * Loads it.
             *
             * @throws StorageException when the backend is unreachable
             * @throws \RuntimeException
             */
            DOC);

        $this->assertCount(2, $doc->throws);
        $this->assertSame('StorageException', $doc->throws[0]['type']);
        $this->assertSame('when the backend is unreachable', $doc->throws[0]['description']);
        $this->assertSame('\RuntimeException', $doc->throws[1]['type']);
    }

    public function testInternalAndDeprecatedAndSinceAreRecognised(): void
    {
        $doc = $this->parser->parse(<<<'DOC'
            /**
             * Old thing.
             *
             * @internal
             * @deprecated 3.2.0 Use the other one.
             * @since 1.0.0
             */
            DOC);

        $this->assertTrue($doc->internal);
        $this->assertSame('3.2.0 Use the other one.', $doc->deprecated);
        $this->assertSame('1.0.0', $doc->since);
    }

    public function testADeprecationWithoutATextIsStillRecorded(): void
    {
        $doc = $this->parser->parse("/**\n * Old.\n *\n * @deprecated\n */");

        $this->assertSame('', $doc->deprecated);
    }

    public function testInheritDocIsFlaggedAndRemovedFromTheProse(): void
    {
        $doc = $this->parser->parse("/**\n * {@inheritDoc}\n */");

        $this->assertTrue($doc->inheritsDoc);
        $this->assertSame('', $doc->summary);
    }

    public function testInheritingFillsOnlyWhatIsMissing(): void
    {
        $parent = $this->parser->parse("/**\n * Parent summary.\n *\n * Parent description.\n */");
        $child = $this->parser->parse("/**\n * Child summary.\n */");

        $merged = $child->inheritFrom($parent);

        $this->assertSame('Child summary.', $merged->summary, 'the override keeps its own summary');
        $this->assertSame('Parent description.', $merged->description, 'and takes what it left out');
        $this->assertFalse($merged->inheritsDoc);
    }

    public function testProseIsRecoveredFromADocblockTheParserCannotRead(): void
    {
        // An unterminated generic is enough to stop the parser; the prose above it is still good.
        $doc = $this->parser->parse('/**' . "\n" . ' * Still readable.' . "\n" . ' *' . "\n" . ' * @param array<int, ' . "\n" . ' */');

        $this->assertSame('Still readable.', $doc->summary);
    }

    public function testASentenceEndingInAnAbbreviationIsNotSplitOnTheAbbreviation(): void
    {
        $doc = $this->parser->parse("/**\n * Reads Psr7.php from disk and returns it.\n */");

        $this->assertSame('Reads Psr7.php from disk and returns it.', $doc->summary);
    }
}
