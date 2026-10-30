<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteCodec;
use Quiote\Replay\Cassette\DbResult;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Cassette\ResourceValue;
use Quiote\Replay\Replay\EffectLedger;

/**
 * pdo_pgsql returns a bytea column as a stream. A resource has no JSON form, so before these
 * placeholders a single bytea value in a recorded row made json_encode() fail with "Type is not
 * supported" and the whole cassette was lost.
 */
final class ResourceValueTest extends TestCase
{
    /** @return resource */
    private static function memoryStream(string $content)
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }

    /** @return array<array-key, mixed> */
    private static function placeholderOf(mixed $value): array
    {
        $placeholder = ResourceValue::replaceResources($value);
        self::assertIsArray($placeholder);
        self::assertTrue(ResourceValue::isPlaceholder($placeholder));

        return $placeholder;
    }

    /** @param array<array-key, mixed> $placeholder */
    private static function reasonOf(array $placeholder): string
    {
        self::assertIsString($placeholder['reason']);

        return $placeholder['reason'];
    }

    public function testASeekableStreamIsRecordedWithItsContent(): void
    {
        $placeholder = self::placeholderOf(self::memoryStream("\x00\xFFbinary"));

        $this->assertSame('stream', $placeholder['$resource']);
        $this->assertSame(8, $placeholder['bytes']);
        $this->assertSame(base64_encode("\x00\xFFbinary"), $placeholder['base64']);
        $this->assertNull($placeholder['reason']);
    }

    public function testRecordingLeavesTheApplicationsStreamWhereItWas(): void
    {
        $stream = self::memoryStream('abcdef');
        fseek($stream, 2);

        self::placeholderOf($stream);

        $this->assertSame(2, ftell($stream));
        $this->assertSame('cdef', stream_get_contents($stream));
    }

    public function testANonSeekableStreamIsNeverRead(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);
        [$reader, $writer] = $pair;
        fwrite($writer, 'payload');

        $placeholder = self::placeholderOf($reader);

        $this->assertNull($placeholder['base64']);
        $this->assertStringContainsString('not seekable', self::reasonOf($placeholder));
        $this->assertSame('payload', fread($reader, 7), 'the application can still read everything');
    }

    public function testAStreamOverTheCaptureLimitIsRecordedWithoutContent(): void
    {
        $placeholder = self::placeholderOf(self::memoryStream(str_repeat('x', ResourceValue::MAX_CAPTURE_BYTES + 1)));

        $this->assertSame(ResourceValue::MAX_CAPTURE_BYTES + 1, $placeholder['bytes']);
        $this->assertNull($placeholder['base64']);
        $this->assertStringContainsString('larger than', self::reasonOf($placeholder));
    }

    public function testAClosedResourceIsRecordedAsSuch(): void
    {
        $stream = self::memoryStream('x');
        fclose($stream);

        $placeholder = self::placeholderOf($stream);

        $this->assertNull($placeholder['base64']);
        $this->assertStringContainsString('closed', self::reasonOf($placeholder));
    }

    public function testANonStreamResourceIsRecordedByType(): void
    {
        $placeholder = self::placeholderOf(stream_context_create());

        $this->assertSame('stream-context', $placeholder['$resource']);
        $this->assertNull($placeholder['base64']);
    }

    public function testResourcesAreReplacedAtAnyDepthAndEverythingElseIsKept(): void
    {
        $replaced = ResourceValue::replaceResourcesIn([
            'rows' => [['id' => 7, 'filedata' => self::memoryStream('pdf')]],
            'note' => 'kept',
        ]);

        $this->assertSame('kept', $replaced['note']);
        $this->assertIsArray($replaced['rows']);
        $this->assertIsArray($replaced['rows'][0]);
        $this->assertSame(7, $replaced['rows'][0]['id']);
        $this->assertTrue(ResourceValue::isPlaceholder($replaced['rows'][0]['filedata']));
    }

    public function testARestoredStreamReadsLikeTheOriginal(): void
    {
        $restored = ResourceValue::restore(self::placeholderOf(self::memoryStream("\x00\xFFbinary")));

        $this->assertSame("\x00\xFFbinary", stream_get_contents($restored));
    }

    public function testAPlaceholderWithoutContentRefusesToBeReplayed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/without its content \(the resource was already closed\)/');

        ResourceValue::restore(['$resource' => 'resource', 'bytes' => null, 'base64' => null, 'reason' => 'the resource was already closed']);
    }

    public function testAnEffectHoldingAByteaStreamSurvivesTheCassetteRoundTrip(): void
    {
        $ledger = new EffectLedger();
        $ledger->record(
            EffectKind::Db,
            'select filedata from files where id = ?',
            ['sql' => 'select filedata from files where id = ?', 'params' => [7]],
            DbResult::rows([['id' => 7, 'filedata' => self::memoryStream('%PDF-1.7')]])->toArray(),
        );
        $codec = new CassetteCodec();
        $cassette = new Cassette(
            schemaVersion: CassetteCodec::CURRENT_SCHEMA_VERSION,
            meta: ['id' => 'BYTEA1'],
            request: [],
            resolved: [],
            session: [],
            user: [],
            effects: $ledger->all(),
            response: [],
            exception: null,
            log: null,
        );

        $decoded = $codec->decode($codec->encode($cassette));
        $result = DbResult::fromResult($decoded->effects[0]->result);

        $this->assertArrayNotHasKey('encoding_lossy', $decoded->meta);
        $this->assertNotNull($result);
        $this->assertNotNull($result->rows);
        $this->assertSame(7, $result->rows[0]['id']);
        $this->assertIsResource($result->rows[0]['filedata']);
        $this->assertSame('%PDF-1.7', stream_get_contents($result->rows[0]['filedata']));
    }
}
