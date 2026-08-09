<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Docs\Slug\Slugger;

final class SluggerTest extends TestCase
{
    private Slugger $slugger;

    protected function setUp(): void
    {
        $this->slugger = new Slugger();
    }

    public function testNestsAPageOnePathSegmentPerNamespaceSegment(): void
    {
        $this->assertSame(
            'routing/compiler/triad-view-resolver',
            $this->slugger->forClass('Quiote\Routing\Compiler\TriadViewResolver'),
        );
    }

    public function testAClassAtTheFrameworkRootHasNoDirectory(): void
    {
        $this->assertSame('context', $this->slugger->forClass('Quiote\Context'));
    }

    public function testTheFrameworkRootNamespaceIsTheReferenceRoot(): void
    {
        $this->assertSame('', $this->slugger->forNamespace('Quiote'));
        $this->assertSame('execution', $this->slugger->forNamespace('Quiote\Execution'));
        $this->assertSame('routing/compiler', $this->slugger->forNamespace('Quiote\Routing\Compiler'));
    }

    /**
     * A capital-per-boundary rule chops these in the wrong place, which is why the slugger
     * keeps an explicit list.
     */
    public function testAcronymsAreNotSplitIntoSeparateWords(): void
    {
        $this->assertSame('apcu-config-cache', $this->slugger->kebab('APCuConfigCache'));
        $this->assertSame('phpunit-test-case-methods', $this->slugger->kebab('PHPUnitTestCaseMethods'));
        $this->assertSame('xml-config-dom-attr', $this->slugger->kebab('XmlConfigDomAttr'));
    }

    public function testOrdinaryMixedCaseNamesNeedNoSpecialHandling(): void
    {
        $this->assertSame('pdo-session-persistence', $this->slugger->kebab('PdoSessionPersistence'));
        $this->assertSame('otlp-decoder', $this->slugger->kebab('OtlpDecoder'));
        $this->assertSame('psr7-delegation-trait', $this->slugger->kebab('Psr7DelegationTrait'));
        $this->assertSame('s3-client', $this->slugger->kebab('S3Client'));
        $this->assertSame('web-request', $this->slugger->kebab('WebRequest'));
    }

    public function testATrailingCapitalRunKeepsItsLastLetterWithTheFollowingWord(): void
    {
        $this->assertSame('i-array-config-handler', $this->slugger->kebab('IArrayConfigHandler'));
    }

    public function testSlugsAreStable(): void
    {
        $first = $this->slugger->forClass('Quiote\Http\Sse\SseStream');
        $second = $this->slugger->forClass('Quiote\Http\Sse\SseStream');

        $this->assertSame($first, $second);
        $this->assertSame('http/sse/sse-stream', $first);
    }
}
