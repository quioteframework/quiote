<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteCodec;
use Quiote\Replay\Cassette\CassetteCodecException;
use Quiote\Replay\Cassette\Effect;
use Quiote\Replay\Cassette\EffectKind;

final class CassetteCodecTest extends TestCase
{
    private function makeCassette(): Cassette
    {
        return new Cassette(
            schemaVersion: CassetteCodec::CURRENT_SCHEMA_VERSION,
            meta: ['id' => '01JCXV8N4K', 'trigger' => 'error'],
            request: ['method' => 'GET', 'uri' => '/widgets', 'body' => ['encoding' => 'utf8', 'content' => 'hello', 'truncated' => false]],
            resolved: ['module' => 'Widgets', 'action' => 'Show'],
            session: ['before' => ['id' => 'abc'], 'after' => ['id' => 'abc'], 'id_rotated' => false],
            user: ['authenticated' => false],
            effects: [new Effect(0, EffectKind::Db, 'select 1', ['sql' => 'select 1'], [['x' => 1]], 120)],
            response: ['status' => 200, 'body' => ['encoding' => 'utf8', 'content' => 'ok', 'truncated' => false]],
            exception: null,
            log: null,
        );
    }

    public function testGzipRoundTripPreservesEveryField(): void
    {
        $codec = new CassetteCodec();
        $cassette = $this->makeCassette();

        $decoded = $codec->decode($codec->encode($cassette));

        $this->assertSame($cassette->schemaVersion, $decoded->schemaVersion);
        $this->assertSame($cassette->meta, $decoded->meta);
        $this->assertSame($cassette->request, $decoded->request);
        $this->assertSame($cassette->resolved, $decoded->resolved);
        $this->assertSame($cassette->session, $decoded->session);
        $this->assertSame($cassette->user, $decoded->user);
        $this->assertSame($cassette->response, $decoded->response);
        $this->assertCount(1, $decoded->effects);
        $this->assertSame(EffectKind::Db, $decoded->effects[0]->kind);
        $this->assertSame('select 1', $decoded->effects[0]->fingerprint);
        $this->assertSame(120, $decoded->effects[0]->durationMicros);
    }

    public function testRawRoundTripProducesPlainNonGzippedJson(): void
    {
        $codec = new CassetteCodec();
        $cassette = $this->makeCassette();

        $raw = $codec->encodeRaw($cassette);
        $this->assertStringStartsWith('{', $raw);
        $decoded = $codec->decodeRaw($raw);

        $this->assertSame($cassette->meta, $decoded->meta);
    }

    public function testBase64BodyEncodingRoundTrips(): void
    {
        $codec = new CassetteCodec();
        $binary = "\xFF\xFE\x00binary";
        $cassette = new Cassette(
            schemaVersion: CassetteCodec::CURRENT_SCHEMA_VERSION,
            meta: [],
            request: ['body' => ['encoding' => 'base64', 'content' => base64_encode($binary), 'truncated' => false]],
            resolved: [],
            session: null,
            user: null,
            effects: [],
            response: [],
            exception: null,
            log: null,
        );

        $decoded = $codec->decode($codec->encode($cassette));

        $body = $decoded->request['body'];
        $this->assertIsArray($body);
        $this->assertIsString($body['content']);
        $this->assertSame($binary, base64_decode($body['content'], true));
    }

    public function testUnknownNewerSchemaVersionIsRefused(): void
    {
        $codec = new CassetteCodec();
        $raw = json_encode(['_schema_version' => 999, 'meta' => [], 'request' => [], 'response' => []]);
        $this->assertIsString($raw);

        $this->expectException(CassetteCodecException::class);
        $this->expectExceptionMessageMatches('/newer than this codec supports/');
        $codec->decodeRaw($raw);
    }

    public function testMissingSchemaVersionIsRefused(): void
    {
        $codec = new CassetteCodec();
        $raw = json_encode(['meta' => [], 'request' => [], 'response' => []]);
        $this->assertIsString($raw);

        $this->expectException(CassetteCodecException::class);
        $codec->decodeRaw($raw);
    }

    public function testMissingRequiredSectionIsRefused(): void
    {
        $codec = new CassetteCodec();
        $raw = json_encode(['_schema_version' => 1, 'meta' => [], 'request' => []]);
        $this->assertIsString($raw);

        $this->expectException(CassetteCodecException::class);
        $this->expectExceptionMessageMatches('/"response"/');
        $codec->decodeRaw($raw);
    }

    public function testTruncatedGzipPayloadIsRefusedWithAClearMessageRatherThanAWarning(): void
    {
        $codec = new CassetteCodec();
        $truncated = substr((new CassetteCodec())->encode($this->makeCassette()), 0, 5);

        $this->expectException(CassetteCodecException::class);
        $this->expectExceptionMessageMatches('/not a valid gzip container/');
        $codec->decode($truncated);
    }

    public function testCorruptJsonInsideAValidGzipContainerIsRefused(): void
    {
        $codec = new CassetteCodec();
        $corrupt = gzencode('{not valid json', 9);
        $this->assertIsString($corrupt);

        $this->expectException(CassetteCodecException::class);
        $this->expectExceptionMessageMatches('/not valid JSON/');
        $codec->decode($corrupt);
    }

    public function testAJsonArrayRatherThanObjectAtTheTopLevelIsRefused(): void
    {
        $codec = new CassetteCodec();

        $this->expectException(CassetteCodecException::class);
        $codec->decodeRaw('[1, 2, 3]');
    }

    public function testAnEffectEntryWithAnUnknownKindIsRefused(): void
    {
        $codec = new CassetteCodec();
        $raw = json_encode([
            '_schema_version' => 1,
            'meta' => [],
            'request' => [],
            'response' => [],
            'effects' => [['seq' => 0, 'kind' => 'not-a-real-kind', 'fingerprint' => 'x', 'call' => []]],
        ]);
        $this->assertIsString($raw);

        $this->expectException(CassetteCodecException::class);
        $this->expectExceptionMessageMatches('/unknown "kind"/');
        $codec->decodeRaw($raw);
    }

    public function testAPayloadInflatingPastTheCeilingIsRefusedRatherThanAllocated(): void
    {
        // Highly compressible, so a small payload claims a large inflated size -- the shape of a
        // decompression bomb. The ceiling is deliberately tiny here so the test stays fast.
        $bomb = gzencode(str_repeat('A', 4_194_304), 9);
        $this->assertIsString($bomb);
        $this->assertLessThan(65_536, strlen($bomb), 'Guard: the payload must be small relative to what it inflates to.');

        $codec = new CassetteCodec(maxDecodedBytes: 65_536);

        $this->expectException(CassetteCodecException::class);
        $this->expectExceptionMessageMatches('/inflates to more than the 65536-byte ceiling/');
        $codec->decode($bomb);
    }

    public function testTheCeilingIsEnforcedWithoutAllocatingTheWholeInflatedPayload(): void
    {
        $bomb = gzencode(str_repeat('A', 33_554_432), 9);
        $this->assertIsString($bomb);

        $before = memory_get_usage(true);
        try {
            (new CassetteCodec(maxDecodedBytes: 65_536))->decode($bomb);
            $this->fail('Expected the payload to be refused.');
        } catch (CassetteCodecException) {
            // expected
        }
        $grew = memory_get_usage(true) - $before;

        // One 8 KiB input chunk cannot inflate to more than about 8 MiB, so refusing a 32 MiB
        // payload must cost far less than 32 MiB. Without the bound this allocates the lot.
        $this->assertLessThan(16_777_216, $grew, sprintf('Refusing the payload allocated %d bytes.', $grew));
    }

    public function testACassetteAtTheCeilingStillRoundTrips(): void
    {
        $cassette = new Cassette(
            schemaVersion: CassetteCodec::CURRENT_SCHEMA_VERSION,
            meta: ['id' => 'big'],
            request: ['method' => 'POST', 'uri' => '/upload', 'body' => ['encoding' => 'utf8', 'content' => str_repeat('payload ', 60_000), 'truncated' => false]],
            resolved: [],
            session: null,
            user: null,
            effects: [],
            response: ['status' => 200, 'body' => ['encoding' => 'utf8', 'content' => 'ok', 'truncated' => false]],
            exception: null,
            log: null,
        );

        $codec = new CassetteCodec();
        $decoded = $codec->decode($codec->encode($cassette));

        $body = $decoded->request['body'];
        $this->assertIsArray($body);
        $this->assertIsString($body['content']);
        $this->assertSame(480_000, strlen($body['content']));
    }

    public function testTheDefaultCeilingSitsWellAboveAPlausibleCassette(): void
    {
        // A cassette bounded by replay.max_bytes' own 2 MiB default, for request and response
        // together, must never come close to the ceiling.
        $this->assertGreaterThan(4 * 2_097_152, CassetteCodec::DEFAULT_MAX_DECODED_BYTES);
    }

    public function testAnEmptyPayloadIsRefused(): void
    {
        $codec = new CassetteCodec();

        $this->expectException(CassetteCodecException::class);
        $this->expectExceptionMessageMatches('/not a valid gzip container/');
        $codec->decode('');
    }

    public function testAPlainNonGzipPayloadIsRefused(): void
    {
        $codec = new CassetteCodec();

        $this->expectException(CassetteCodecException::class);
        $this->expectExceptionMessageMatches('/not a valid gzip container/');
        $codec->decode('{"_schema_version":1}');
    }

    public function testAPayloadLargerThanOneInflateChunkRoundTrips(): void
    {
        // Crosses the 8 KiB input-chunk boundary many times over, so the incremental inflater's
        // multi-chunk path is what is exercised rather than a single-shot one.
        $cassette = new Cassette(
            schemaVersion: CassetteCodec::CURRENT_SCHEMA_VERSION,
            meta: ['id' => 'chunked'],
            request: ['method' => 'GET', 'uri' => '/x', 'body' => ['encoding' => 'utf8', 'content' => bin2hex(random_bytes(200_000)), 'truncated' => false]],
            resolved: [],
            session: null,
            user: null,
            effects: [],
            response: ['status' => 200],
            exception: null,
            log: null,
        );

        $codec = new CassetteCodec();
        $encoded = $codec->encode($cassette);
        $this->assertGreaterThan(8_192 * 4, strlen($encoded), 'Guard: the compressed payload must span several input chunks.');

        $decodedBody = $codec->decode($encoded)->request['body'];
        $originalBody = $cassette->request['body'];
        $this->assertIsArray($decodedBody);
        $this->assertIsArray($originalBody);
        $this->assertSame($originalBody['content'], $decodedBody['content']);
    }

    public function testTwoEffectsSharingASeqAreRefused(): void
    {
        // EffectLedger keys its consumed-set by seq, so a duplicate marks both consumed at once:
        // matching the first makes the second unreachable and turns it into a phantom unplayed()
        // entry. The recorder never emits one, so a cassette carrying one has been edited.
        $raw = json_encode([
            '_schema_version' => 1,
            'meta' => [],
            'request' => [],
            'response' => [],
            'effects' => [
                ['seq' => 0, 'kind' => 'db', 'fingerprint' => 'a', 'call' => []],
                ['seq' => 0, 'kind' => 'db', 'fingerprint' => 'b', 'call' => []],
            ],
        ]);
        $this->assertIsString($raw);

        $this->expectException(CassetteCodecException::class);
        $this->expectExceptionMessageMatches('/two effects with seq 0/');
        (new CassetteCodec())->decodeRaw($raw);
    }

    public function testDistinctSeqValuesAreAccepted(): void
    {
        $raw = json_encode([
            '_schema_version' => 1,
            'meta' => [],
            'request' => [],
            'response' => [],
            'effects' => [
                ['seq' => 0, 'kind' => 'db', 'fingerprint' => 'a', 'call' => []],
                ['seq' => 1, 'kind' => 'db', 'fingerprint' => 'b', 'call' => []],
            ],
        ]);
        $this->assertIsString($raw);

        $this->assertCount(2, (new CassetteCodec())->decodeRaw($raw)->effects);
    }
}
