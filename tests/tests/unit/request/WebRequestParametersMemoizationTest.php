<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Request\WebRequest;

/**
 * Covers the getParameters() memoization cache and its invalidation on every
 * mutator, plus the bulk withUnvalidatedParameters()/withoutHeaders() paths
 * added alongside it.
 */
class WebRequestParametersMemoizationTest extends UnitTestCase
{
    private WebRequest $r;

    #[\Override]
    public function setUp(): void
    {
        $this->r = new WebRequest();
        $this->r->initialize($this->getContext());
    }

    public function testGetParametersCacheReturnsConsistentValueAcrossCalls(): void
    {
        $this->r = $this->r->enforceValidatedParameters(['foo']);
        $this->r = $this->r->setParameter('foo', 'bar');

        $first = $this->r->getParameters();
        $second = $this->r->getParameters();

        $this->assertSame($first, $second);
        $this->assertSame('bar', $first['foo']);
    }

    public function testSetParameterInvalidatesCache(): void
    {
        $this->r = $this->r->enforceValidatedParameters(['foo']);
        $this->r = $this->r->setParameter('foo', 'bar');
        $this->r->getParameters(); // populate cache

        $this->r = $this->r->setParameter('foo', 'baz');

        $this->assertSame('baz', $this->r->getParameters()['foo']);
    }

    public function testWithParametersInvalidatesCache(): void
    {
        $this->r = $this->r->enforceValidatedParameters(['foo']);
        $this->r->getParameters(); // populate cache on the base instance

        $this->r = $this->r->withParameters(['foo' => 'bar']);

        $this->assertSame('bar', $this->r->getParameters()['foo']);
    }

    public function testQueryParamChangeInvalidatesCache(): void
    {
        $this->r = $this->r->enforceValidatedParameters(['q']);
        $this->r->getParameters(); // populate cache with empty query

        $this->r = $this->r->withQueryParams(['q' => 'search']);

        $this->assertSame('search', $this->r->getParameters()['q']);
    }

    public function testWithUnvalidatedParametersBulkMatchesPerKeyLoop(): void
    {
        $viaLoop = $this->r
            ->setUnvalidatedParameter('a', '1')
            ->setUnvalidatedParameter('b', '2');

        $viaBulk = $this->r->withUnvalidatedParameters(['a' => '1', 'b' => '2']);

        $this->assertSame($viaLoop->getRuntimeParameterKeys(), $viaBulk->getRuntimeParameterKeys());
        // Unvalidated: present in runtime store but not whitelisted for getParameter().
        $this->assertNull($viaBulk->getParameter('a', null));
    }

    public function testWithUnvalidatedParametersEmptyArrayIsNoOp(): void
    {
        $same = $this->r->withUnvalidatedParameters([]);
        $this->assertSame($this->r, $same);
    }

    public function testPruneExtendedSourcesKeepsOnlyWhitelistedHeaders(): void
    {
        $this->r = $this->r
            ->withHeader('X-Keep', 'yes')
            ->withHeader('X-Drop', 'no')
            ->withHeader('X-Also-Drop', 'no');

        $pruned = $this->r->pruneExtendedSources(
            ['X-Keep' => true],
            [],
            [],
            [],
            [],
            []
        );

        $this->assertTrue($pruned->hasHeader('X-Keep'));
        $this->assertFalse($pruned->hasHeader('X-Drop'));
        $this->assertFalse($pruned->hasHeader('X-Also-Drop'));
    }

    public function testPruneExtendedSourcesWithEmptyKeepSetRemovesAllHeaders(): void
    {
        $this->r = $this->r
            ->withHeader('X-One', '1')
            ->withHeader('X-Two', '2');

        $pruned = $this->r->pruneExtendedSources([], [], [], [], [], []);

        $this->assertSame([], $pruned->getHeaders());
    }
}
