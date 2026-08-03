<?php

use PHPUnit\Framework\TestCase;
use Quiote\Response\CookieSerializer;

class ResponseCookieSerializerTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array{value: mixed, lifetime: int|string|null, path: string|null, domain: string|null, secure: bool, httponly: bool, encode_callback: callable|false, samesite: string|null}
     */
    private function cookie(array $overrides = []): array
    {
        /** @var array{value: mixed, lifetime: int|string|null, path: string|null, domain: string|null, secure: bool, httponly: bool, encode_callback: callable|false, samesite: string|null} $cookie */
        $cookie = array_merge([
            'value' => 'v',
            'lifetime' => 0,
            'path' => null,
            'domain' => null,
            'secure' => false,
            'httponly' => true,
            'encode_callback' => false,
            'samesite' => 'Lax',
        ], $overrides);

        return $cookie;
    }

    public function testSessionCookieHasNoExpiryOrMaxAge(): void
    {
        $normalized = (new CookieSerializer())->normalize('sid', $this->cookie(['lifetime' => 0]));

        $this->assertSame('v', $normalized['value']);
        $this->assertNull($normalized['expires']);
        $this->assertNull($normalized['max_age']);
    }

    public function testNumericLifetimeBecomesAnAbsoluteExpiryAndMaxAge(): void
    {
        $before = time();
        $normalized = (new CookieSerializer())->normalize('sid', $this->cookie(['lifetime' => 3600]));

        $this->assertNotNull($normalized['expires']);
        $this->assertGreaterThanOrEqual($before + 3600, $normalized['expires']);
        $this->assertNotNull($normalized['max_age']);
        $this->assertLessThanOrEqual(3600, $normalized['max_age']);
    }

    public function testStrtotimeLifetimeIsParsed(): void
    {
        $normalized = (new CookieSerializer())->normalize('sid', $this->cookie(['lifetime' => '+1 day']));

        $this->assertNotNull($normalized['expires']);
        $this->assertGreaterThan(time(), $normalized['expires']);
    }

    public function testUnparseableLifetimeDegradesToASessionCookie(): void
    {
        $normalized = (new CookieSerializer())->normalize('sid', $this->cookie(['lifetime' => 'not a date']));

        $this->assertNull($normalized['expires']);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function deletionValueProvider(): array
    {
        return ['null' => [null], 'false' => [false], 'empty string' => ['']];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('deletionValueProvider')]
    public function testAnEmptyValueMeansDeletion(mixed $value): void
    {
        $normalized = (new CookieSerializer())->normalize('sid', $this->cookie(['value' => $value]));

        $this->assertSame('', $normalized['value']);
        $this->assertNotNull($normalized['expires']);
        $this->assertLessThan(time(), $normalized['expires']);
        $this->assertSame(0, $normalized['max_age']);
    }

    public function testEncodeCallbackIsApplied(): void
    {
        $normalized = (new CookieSerializer())->normalize(
            'sid',
            $this->cookie(['value' => 'a b', 'encode_callback' => 'urlencode'])
        );

        $this->assertSame('a+b', $normalized['value']);
    }

    /**
     * A cookie is not worth failing a response over, so a throwing encoder falls back to the
     * raw value.
     */
    public function testAThrowingEncodeCallbackFallsBackToTheRawValue(): void
    {
        $normalized = (new CookieSerializer())->normalize('sid', $this->cookie([
            'value' => 'raw',
            'encode_callback' => static function (): never { throw new RuntimeException('boom'); },
        ]));

        $this->assertSame('raw', $normalized['value']);
    }

    public function testEncodeCallbackIsSkippedForADeletion(): void
    {
        $normalized = (new CookieSerializer())->normalize('sid', $this->cookie([
            'value' => '',
            'encode_callback' => static function (): never { throw new RuntimeException('must not run'); },
        ]));

        $this->assertSame('', $normalized['value']);
    }

    public function testDeclaredPathWins(): void
    {
        $normalized = (new CookieSerializer('/base'))->normalize('sid', $this->cookie(['path' => '/own']));

        $this->assertSame('/own', $normalized['path']);
    }

    public function testDefaultPathIsUsedWhenNoneIsDeclared(): void
    {
        $normalized = (new CookieSerializer('/base'))->normalize('sid', $this->cookie(['path' => null]));

        $this->assertSame('/base', $normalized['path']);
    }

    public function testAnEmptyPathNeverReachesTheWire(): void
    {
        $this->assertSame('/', (new CookieSerializer(''))->normalize('sid', $this->cookie(['path' => null]))['path']);
        $this->assertSame('/', (new CookieSerializer('/base'))->normalize('sid', $this->cookie(['path' => '']))['path']);
    }

    public function testEmptyDomainBecomesNull(): void
    {
        $this->assertNull((new CookieSerializer())->normalize('sid', $this->cookie(['domain' => '']))['domain']);
        $this->assertSame('.example.test', (new CookieSerializer())->normalize('sid', $this->cookie(['domain' => '.example.test']))['domain']);
    }

    public function testSameSiteIsCanonicalized(): void
    {
        $this->assertSame('Strict', (new CookieSerializer())->normalize('sid', $this->cookie(['samesite' => 'STRICT']))['samesite']);
        $this->assertSame('None', (new CookieSerializer())->normalize('sid', $this->cookie(['samesite' => 'none']))['samesite']);
        $this->assertNull((new CookieSerializer())->normalize('sid', $this->cookie(['samesite' => '']))['samesite']);
        $this->assertNull((new CookieSerializer())->normalize('sid', $this->cookie(['samesite' => null]))['samesite']);
    }

    public function testNonScalarValueSerializesEmpty(): void
    {
        $normalized = (new CookieSerializer())->normalize('sid', $this->cookie(['value' => ['a', 'b']]));

        $this->assertSame('', $normalized['value']);
    }

    public function testHeaderIncludesEveryDeclaredAttribute(): void
    {
        $serializer = new CookieSerializer();
        $normalized = $serializer->normalize('sid', $this->cookie([
            'value' => 'abc',
            'lifetime' => 3600,
            'path' => '/app',
            'domain' => 'example.test',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]));

        $header = $serializer->header('sid', $normalized);

        $this->assertStringStartsWith('sid=abc; ', $header);
        $this->assertStringContainsString('Expires=', $header);
        $this->assertStringContainsString('Max-Age=', $header);
        $this->assertStringContainsString('Path=/app', $header);
        $this->assertStringContainsString('Domain=example.test', $header);
        $this->assertStringContainsString('Secure', $header);
        $this->assertStringContainsString('HttpOnly', $header);
        $this->assertStringContainsString('SameSite=Strict', $header);
    }

    public function testDeletionHeaderCarriesMaxAgeZero(): void
    {
        $serializer = new CookieSerializer();
        $header = $serializer->header('sid', $serializer->normalize('sid', $this->cookie(['value' => null])));

        $this->assertStringStartsWith('sid=;', $header);
        $this->assertStringContainsString('Max-Age=0', $header);
        $this->assertStringContainsString('Expires=', $header);
    }

    public function testSessionCookieHeaderOmitsExpiryAttributes(): void
    {
        $serializer = new CookieSerializer();
        $header = $serializer->header('sid', $serializer->normalize('sid', $this->cookie(['lifetime' => 0])));

        $this->assertStringNotContainsString('Expires=', $header);
        $this->assertStringNotContainsString('Max-Age=', $header);
    }

    public function testHeaderOmitsUnsetOptionalAttributes(): void
    {
        $serializer = new CookieSerializer();
        $header = $serializer->header('sid', $serializer->normalize('sid', $this->cookie([
            'secure' => false,
            'httponly' => false,
            'samesite' => null,
            'domain' => null,
        ])));

        $this->assertStringNotContainsString('Secure', $header);
        $this->assertStringNotContainsString('HttpOnly', $header);
        $this->assertStringNotContainsString('SameSite', $header);
        $this->assertStringNotContainsString('Domain', $header);
    }

    public function testHeadersSerializesEveryQueuedCookieInOrder(): void
    {
        $lines = (new CookieSerializer())->headers([
            'first' => $this->cookie(['value' => '1']),
            'second' => $this->cookie(['value' => '2']),
        ]);

        $this->assertCount(2, $lines);
        $this->assertStringStartsWith('first=1', $lines[0]);
        $this->assertStringStartsWith('second=2', $lines[1]);
    }

    public function testHeadersOnAnEmptyQueueIsAnEmptyList(): void
    {
        $this->assertSame([], (new CookieSerializer())->headers([]));
    }
}
