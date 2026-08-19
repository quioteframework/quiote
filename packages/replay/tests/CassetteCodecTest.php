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
}
