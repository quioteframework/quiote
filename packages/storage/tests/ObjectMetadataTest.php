<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quiote\Storage\ObjectMetadata;

/**
 * `ObjectMetadata::fromResponse()` is the one piece of parsing S3, GCS and Azure all share, so a
 * misreading here is a misreading for every provider at once. Its contract is that each field
 * independently degrades to null and that nothing throws, however odd the headers are: a caller
 * needing a value says so with its own error rather than being handed a silently invented zero.
 */
final class ObjectMetadataTest extends TestCase
{
    /** @param array<string, string> $headers */
    private function metadataFor(array $headers): ObjectMetadata
    {
        return ObjectMetadata::fromResponse(new Response(200, $headers));
    }

    public function testReadsAllThreeFieldsFromACompleteHeadResponse(): void
    {
        $metadata = $this->metadataFor([
            'Content-Length' => '4096',
            'Last-Modified' => 'Wed, 21 Oct 2015 07:28:00 GMT',
            'ETag' => '"0x8D2C1F4E8A9B3C1"',
        ]);

        $this->assertSame(4096, $metadata->contentLength);
        $this->assertSame('0x8D2C1F4E8A9B3C1', $metadata->etag);
        $this->assertNotNull($metadata->lastModified);
        $this->assertSame('2015-10-21T07:28:00+00:00', $metadata->lastModified->format('c'));
    }

    public function testAResponseWithNoneOfTheHeadersIsAllNullRatherThanAnError(): void
    {
        // A proxy or an S3-compatible server is not obliged to send any of them.
        $metadata = $this->metadataFor([]);

        $this->assertNull($metadata->contentLength);
        $this->assertNull($metadata->lastModified);
        $this->assertNull($metadata->etag);
    }

    public function testAZeroLengthObjectKeepsItsLengthInsteadOfDegradingToNull(): void
    {
        // Zero is a real length, and the difference between "empty object" and "server did not
        // say" is the whole reason the field is nullable.
        $this->assertSame(0, $this->metadataFor(['Content-Length' => '0'])->contentLength);
    }

    /** @return iterable<string, array{string}> */
    public static function unusableContentLengths(): iterable
    {
        yield 'empty' => [''];
        yield 'not a number' => ['unknown'];
        yield 'fractional' => ['12.5'];
        yield 'negative' => ['-1'];
        yield 'trailing junk' => ['42 bytes'];
        yield 'hex' => ['0x20'];
    }

    #[DataProvider('unusableContentLengths')]
    public function testAContentLengthThatIsNotAllDigitsBecomesNull(string $header): void
    {
        // Not zero: a caller sizing a buffer or a range request off a bad header would read the
        // wrong thing, and null makes it say so.
        $this->assertNull($this->metadataFor(['Content-Length' => $header])->contentLength);
    }

    public function testSurroundingWhitespaceIsNotThisClasssProblem(): void
    {
        // Worth pinning because `ctype_digit(' 42')` is false, so it looks like this ought to
        // reject a padded value: it never sees one. Optional whitespace around a header value is
        // not part of the value in HTTP, and PSR-7 strips it before getHeaderLine() returns.
        $this->assertSame(42, $this->metadataFor(['Content-Length' => ' 42 '])->contentLength);
    }

    public function testLeadingZerosAreReadAsTheNumberTheySpell(): void
    {
        // A digit string per the grammar, so tolerated rather than rejected.
        $this->assertSame(42, $this->metadataFor(['Content-Length' => '0042'])->contentLength);
        $this->assertSame(0, $this->metadataFor(['Content-Length' => '000'])->contentLength);
    }

    public function testTheLargestRepresentableLengthIsStillRead(): void
    {
        // The boundary the guard below must not overshoot: PHP_INT_MAX itself is exact.
        $this->assertSame(
            PHP_INT_MAX,
            $this->metadataFor(['Content-Length' => (string) PHP_INT_MAX])->contentLength,
        );
    }

    public function testAContentLengthTooLargeForAnIntIsNotSilentlySaturated(): void
    {
        // ctype_digit() passes, so this is the one numeric case that could get through wrong.
        // 2^63 exceeds PHP_INT_MAX, and (int) would clamp it to PHP_INT_MAX -- a plausible-looking
        // number that is not the object's size.
        $metadata = $this->metadataFor(['Content-Length' => '9223372036854775808']);

        $this->assertNull(
            $metadata->contentLength,
            'a length that cannot be represented should read as absent, not as PHP_INT_MAX',
        );
    }

    public function testAMalformedLastModifiedBecomesNullRatherThanThrowing(): void
    {
        // DateTimeImmutable throws on this; a timestamp nobody can trust is worse than none.
        $this->assertNull($this->metadataFor(['Last-Modified' => 'not a date'])->lastModified);
    }

    public function testAnImfFixdateIsReadAsUtcRatherThanTheLocalZone(): void
    {
        // The GMT in an IMF-fixdate is part of the value. Reading it in the server's own zone
        // would shift every timestamp by the offset, which is the sort of thing that looks fine
        // until an app compares one against a locally-generated time.
        $metadata = $this->metadataFor(['Last-Modified' => 'Sun, 06 Nov 1994 08:49:37 GMT']);

        $this->assertNotNull($metadata->lastModified);
        $this->assertSame(784111777, $metadata->lastModified->getTimestamp());
    }

    public function testAStrongEtagLosesItsQuotes(): void
    {
        $this->assertSame('abc123', $this->metadataFor(['ETag' => '"abc123"'])->etag);
    }

    public function testAWeakEtagKeepsItsMarkerAndQuotesIntact(): void
    {
        // Trimming quote characters off both ends -- the obvious one-liner -- yields `W/"abc`,
        // which is neither the weak tag nor the strong one. And reducing it to `abc` would be
        // worse: a weak validator is not interchangeable with the strong tag of the same opaque
        // value, so a caller comparing them would treat a "semantically equivalent" body as
        // byte-identical.
        $this->assertSame('W/"abc"', $this->metadataFor(['ETag' => 'W/"abc"'])->etag);
    }

    public function testAnEmptyOrAbsentEtagBecomesNull(): void
    {
        $this->assertNull($this->metadataFor(['ETag' => ''])->etag);
        $this->assertNull($this->metadataFor(['ETag' => '""'])->etag, 'quotes around nothing');
        $this->assertNull($this->metadataFor([])->etag);
    }

    public function testAnUnquotedEtagIsPassedThroughAsItArrived(): void
    {
        // Malformed per RFC 9110, but a proxy sending one means it is what the server considers
        // the validator; inventing quotes for it would be guessing.
        $this->assertSame('abc123', $this->metadataFor(['ETag' => 'abc123'])->etag);
    }

    public function testFieldsDegradeIndependentlyOfEachOther(): void
    {
        // The point of parsing each one separately: a broken Last-Modified must not cost the
        // caller the length and the ETag it could have had.
        $metadata = $this->metadataFor([
            'Content-Length' => '17',
            'Last-Modified' => 'garbage',
            'ETag' => '"still-here"',
        ]);

        $this->assertSame(17, $metadata->contentLength);
        $this->assertNull($metadata->lastModified);
        $this->assertSame('still-here', $metadata->etag);
    }

    public function testARepeatedContentLengthHeaderIsRefusedRatherThanHalfRead(): void
    {
        // PSR-7 joins repeated values with a comma, so `12, 12` is what parsing sees. Reading the
        // first number off it would be trusting a response two hops disagree about.
        $response = (new Response(200))->withHeader('Content-Length', ['12', '12']);

        $this->assertNull(ObjectMetadata::fromResponse($response)->contentLength);
    }

    public function testHeaderNamesAreMatchedCaseInsensitively(): void
    {
        // Guaranteed by PSR-7, and worth pinning: providers differ on casing, and a case-sensitive
        // read would work against one server and silently return nulls against another.
        $metadata = $this->metadataFor([
            'content-length' => '8',
            'last-modified' => 'Wed, 21 Oct 2015 07:28:00 GMT',
            'etag' => '"lower"',
        ]);

        $this->assertSame(8, $metadata->contentLength);
        $this->assertNotNull($metadata->lastModified);
        $this->assertSame('lower', $metadata->etag);
    }

    public function testItIsAlsoUsableForAGetResponse(): void
    {
        // Documented as reading a HEAD *or* GET response; a body must not disturb the parse.
        $response = new Response(200, ['Content-Length' => '5', 'ETag' => '"g"'], 'hello');

        $metadata = ObjectMetadata::fromResponse($response);

        $this->assertSame(5, $metadata->contentLength);
        $this->assertSame('g', $metadata->etag);
    }
}
