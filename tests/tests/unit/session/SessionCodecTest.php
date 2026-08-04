<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quiote\Exception\StorageException;
use Quiote\Session\SessionCodec;

/**
 * The codec is a wire format, so what matters is that anything one configuration writes another
 * can read. These assert that property directly rather than the encoding of any one payload.
 */
class SessionCodecTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function payloadProvider(): array
    {
        return [
            'empty' => [[]],
            'scalars' => [['id' => 42, 'name' => 'alice', 'active' => true, 'score' => 1.5]],
            'null value' => [['maybe' => null]],
            'nested' => [['user' => ['id' => 7, 'roles' => ['admin', 'editor']]]],
            'unicode' => [['name' => 'Ærlig Ømsjø 日本語']],
            'slashes' => [['path' => 'a/b/c']],
            'deep' => [['a' => ['b' => ['c' => ['d' => 'e']]]]],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('payloadProvider')]
    public function testBinaryPreferredRoundTrip(array $payload): void
    {
        $codec = SessionCodec::binaryPreferred();

        $this->assertSame($payload, $codec->decode($codec->encode($payload)));
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('payloadProvider')]
    public function testPortableRoundTrip(array $payload): void
    {
        $codec = SessionCodec::portable();

        $this->assertSame($payload, $codec->decode($codec->encode($payload)));
    }

    /**
     * The property the previous per-backend implementations could not guarantee: a payload
     * written under one configuration is readable under the other, in both directions.
     *
     * @param array<string, mixed> $payload
     */
    #[DataProvider('payloadProvider')]
    public function testEitherCodecReadsWhatTheOtherWrote(array $payload): void
    {
        $binary = SessionCodec::binaryPreferred();
        $portable = SessionCodec::portable();

        $this->assertSame($payload, $portable->decode($binary->encode($payload)), 'portable must read binary');
        $this->assertSame($payload, $binary->decode($portable->encode($payload)), 'binary must read portable');
    }

    public function testPortableAlwaysWritesJson(): void
    {
        $encoded = SessionCodec::portable()->encode(['k' => 'v']);

        $this->assertSame('{"k":"v"}', $encoded);
    }

    public function testBinaryPreferredWritesIgbinaryWhenAvailable(): void
    {
        if (!function_exists('igbinary_serialize')) {
            $this->assertSame('{"k":"v"}', SessionCodec::binaryPreferred()->encode(['k' => 'v']));

            return;
        }

        $encoded = SessionCodec::binaryPreferred()->encode(['k' => 'v']);

        $this->assertStringStartsNotWith('{', $encoded, 'igbinary output must not look like JSON');
        $this->assertSame(['k' => 'v'], SessionCodec::binaryPreferred()->decode($encoded));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unreadableProvider(): array
    {
        return [
            'empty string' => [''],
            'truncated json object' => ['{"a":'],
            'plain text' => ['not a session at all'],
            'json scalar' => ['"just a string"'],
            'json number' => ['42'],
        ];
    }

    #[DataProvider('unreadableProvider')]
    public function testUnreadablePayloadDecodesToNull(string $payload): void
    {
        $this->assertNull(SessionCodec::binaryPreferred()->decode($payload));
        $this->assertNull(SessionCodec::portable()->decode($payload));
    }

    /**
     * A JSON list decodes to integer keys, which is not session data. Handing it back would make
     * the caller's key lookups silently miss.
     */
    public function testJsonListIsNotSessionData(): void
    {
        $this->assertNull(SessionCodec::portable()->decode('[1,2,3]'));
        $this->assertNull(SessionCodec::portable()->decode('["a","b"]'));
    }

    public function testMixedKeyedArrayIsRejected(): void
    {
        $codec = SessionCodec::portable();
        // Encodes to a JSON object with a numeric key, which decodes back to an int key.
        $encoded = $codec->encode(['ok' => 1]);
        $this->assertNotNull($codec->decode($encoded));

        $this->assertNull($codec->decode('{"0":"a","b":"c"}'), 'an integer key means this is not session data');
    }

    /**
     * An empty JSON object is a real, empty session -- distinct from "no session".
     */
    public function testEmptyObjectDecodesToAnEmptyArrayNotNull(): void
    {
        $decoded = SessionCodec::portable()->decode('{}');

        $this->assertSame([], $decoded);
    }

    public function testUnencodablePayloadThrows(): void
    {
        $this->expectException(StorageException::class);

        // A resource has no representation in either format.
        $handle = fopen('php://temp', 'r');
        $this->assertNotFalse($handle);
        try {
            SessionCodec::portable()->encode(['stream' => $handle]);
        } finally {
            fclose($handle);
        }
    }

    /**
     * A top-level key that PHP coerces to an integer cannot survive a round trip, because the
     * decoded array is then a list rather than session data. This is a property of PHP's array
     * keys, not of the encoding: `['0' => 'x']` is already `[0 => 'x']` before the codec sees it.
     * Session keys therefore have to be non-numeric strings, which is what every caller in the
     * framework uses.
     */
    public function testANumericTopLevelKeyCannotRoundTrip(): void
    {
        $codec = SessionCodec::portable();

        $this->assertNull($codec->decode('{"0":"zero"}'));
        // Nested numeric keys are fine -- only the top level has to be string-keyed.
        $this->assertSame(
            ['list' => ['a', 'b']],
            $codec->decode($codec->encode(['list' => ['a', 'b']]))
        );
    }

    public function testConstructorFlagMatchesTheNamedConstructors(): void
    {
        $payload = ['k' => 'v'];

        $this->assertSame(
            (new SessionCodec(preferBinary: false))->encode($payload),
            SessionCodec::portable()->encode($payload)
        );
        $this->assertSame(
            (new SessionCodec(preferBinary: true))->encode($payload),
            SessionCodec::binaryPreferred()->encode($payload)
        );
    }
}
