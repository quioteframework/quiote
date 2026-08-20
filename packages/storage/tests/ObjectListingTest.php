<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Storage\ObjectListing;
use Quiote\Storage\ObjectSummary;

/**
 * The paging contract every provider's `listObjects()` is normalized onto. `isTruncated()` is one
 * line, but it is the line a caller's paging loop terminates on, and getting its sense inverted
 * would either drop every page after the first or loop forever.
 */
final class ObjectListingTest extends TestCase
{
    public function testAContinuationTokenMeansAnotherPageFollows(): void
    {
        $listing = new ObjectListing([], [], 'opaque-token');

        $this->assertTrue($listing->isTruncated());
        $this->assertSame('opaque-token', $listing->nextContinuationToken);
    }

    public function testNoContinuationTokenMeansTheLastPage(): void
    {
        $this->assertFalse((new ObjectListing([], [], null))->isTruncated());
    }

    public function testAFullPageWithNoTokenIsStillTheLastPage(): void
    {
        // The token is the only signal. A provider that fills a page exactly and has nothing more
        // sends no token, and treating a full page as "probably more" would cost an extra request
        // per listing at best and loop at worst.
        $listing = new ObjectListing(
            [new ObjectSummary('a', 1, null, null), new ObjectSummary('b', 2, null, null)],
            [],
            null,
        );

        $this->assertFalse($listing->isTruncated());
        $this->assertCount(2, $listing->objects);
    }

    public function testAnEmptyPageCanStillBeTruncated(): void
    {
        // Azure and S3 both do this: a page whose every key was filtered out still carries the
        // token for the next one, so an empty page must not end the loop.
        $this->assertTrue((new ObjectListing([], [], 'more'))->isTruncated());
    }

    public function testObjectsAndCommonPrefixesAreKeptApart(): void
    {
        // A delimited listing returns both, and folding them into one list would make a
        // "directory" indistinguishable from a zero-byte key of the same name.
        $listing = new ObjectListing(
            [new ObjectSummary('logs/2026-08-19.txt', 12, null, '"e"')],
            ['logs/archive/'],
            null,
        );

        $this->assertSame(['logs/archive/'], $listing->commonPrefixes);
        $this->assertCount(1, $listing->objects);
        $this->assertSame('logs/2026-08-19.txt', $listing->objects[0]->key);
    }

    public function testASummaryCarriesTheKeyAlongsideTheSameThreeFieldsAsObjectMetadata(): void
    {
        // The documented reason ObjectSummary is its own type: a listing entry and a head() result
        // describe the same three things, so they must read the same way.
        $modified = new DateTimeImmutable('2026-08-19 10:00:00', new DateTimeZone('UTC'));
        $summary = new ObjectSummary('reports/q1.csv', 2048, $modified, 'abc');

        $this->assertSame('reports/q1.csv', $summary->key);
        $this->assertSame(2048, $summary->size);
        $this->assertSame($modified, $summary->lastModified);
        $this->assertSame('abc', $summary->etag);
    }

    public function testASummaryToleratesAbsentMetadataTheWayObjectMetadataDoes(): void
    {
        // A listing response is no more obliged to carry size or ETag per entry than a HEAD is.
        $summary = new ObjectSummary('k', null, null, null);

        $this->assertSame('k', $summary->key);
        $this->assertNull($summary->size);
        $this->assertNull($summary->lastModified);
        $this->assertNull($summary->etag);
    }
}
