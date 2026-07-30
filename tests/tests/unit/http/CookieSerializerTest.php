<?php

use PHPUnit\Framework\TestCase;
use Quiote\Http\CookieSerializer;
use Quiote\Http\Psr17;

class CookieSerializerTest extends TestCase
{
    private function globalResponse(mixed $cookies): object
    {
        return new class ($cookies) {
            public function __construct(private mixed $cookies) {}

            public function getCookies(): mixed
            {
                return $this->cookies;
            }
        };
    }

    public function testBridgeWithoutGetCookiesMethodReturnsResponseUnchanged(): void
    {
        $response = Psr17::factory()->createResponse(200);
        $globalResp = new class {};

        $result = CookieSerializer::bridge($globalResp, $response);

        $this->assertSame([], $result->getHeader('Set-Cookie'));
    }

    public function testBridgeWithNonArrayCookiesReturnsResponseUnchanged(): void
    {
        $response = Psr17::factory()->createResponse(200);
        $globalResp = $this->globalResponse('not-an-array');

        $result = CookieSerializer::bridge($globalResp, $response);

        $this->assertSame([], $result->getHeader('Set-Cookie'));
    }

    public function testBridgeAppendsBasicCookie(): void
    {
        $response = Psr17::factory()->createResponse(200);
        $globalResp = $this->globalResponse([
            'session' => ['value' => 'abc123', 'lifetime' => 0],
        ]);

        $result = CookieSerializer::bridge($globalResp, $response);

        $headers = $result->getHeader('Set-Cookie');
        $this->assertCount(1, $headers);
        $this->assertStringStartsWith('session=abc123', $headers[0]);
        $this->assertStringContainsString('; Path=/', $headers[0]);
    }

    public function testBridgeUrlEncodesValueByDefault(): void
    {
        $response = Psr17::factory()->createResponse(200);
        $globalResp = $this->globalResponse([
            'weird' => ['value' => 'a b;c', 'lifetime' => 0],
        ]);

        $result = CookieSerializer::bridge($globalResp, $response);

        $this->assertStringContainsString('weird=' . rawurlencode('a b;c'), $result->getHeader('Set-Cookie')[0]);
    }

    public function testBridgeHonorsExplicitEncodeCallback(): void
    {
        $response = Psr17::factory()->createResponse(200);
        $globalResp = $this->globalResponse([
            'custom' => [
                'value' => 'raw',
                'lifetime' => 0,
                'encode_callback' => static fn (string $v): string => strtoupper($v),
            ],
        ]);

        $result = CookieSerializer::bridge($globalResp, $response);

        $this->assertStringStartsWith('custom=RAW', $result->getHeader('Set-Cookie')[0]);
    }

    public function testBridgeWithEncodeCallbackFalseLeavesValueAsIs(): void
    {
        $response = Psr17::factory()->createResponse(200);
        $globalResp = $this->globalResponse([
            'pre' => ['value' => 'already%20encoded', 'lifetime' => 0, 'encode_callback' => false],
        ]);

        $result = CookieSerializer::bridge($globalResp, $response);

        $this->assertStringStartsWith('pre=already%20encoded', $result->getHeader('Set-Cookie')[0]);
    }

    public function testBridgeSetsExpiresAndMaxAgeForPositiveLifetime(): void
    {
        $response = Psr17::factory()->createResponse(200);
        $globalResp = $this->globalResponse([
            'persist' => ['value' => 'v', 'lifetime' => 3600],
        ]);

        $result = CookieSerializer::bridge($globalResp, $response);
        $header = $result->getHeader('Set-Cookie')[0];

        $this->assertStringContainsString('; Expires=', $header);
        $this->assertStringContainsString('; Max-Age=', $header);
    }

    public function testBridgeWithStringLifetimeUsesStrtotime(): void
    {
        $response = Psr17::factory()->createResponse(200);
        $globalResp = $this->globalResponse([
            'timed' => ['value' => 'v', 'lifetime' => '+1 hour'],
        ]);

        $result = CookieSerializer::bridge($globalResp, $response);
        $header = $result->getHeader('Set-Cookie')[0];

        $this->assertStringContainsString('; Expires=', $header);
    }

    public function testBridgeClearsCookieWithEmptyValue(): void
    {
        $response = Psr17::factory()->createResponse(200);
        $globalResp = $this->globalResponse([
            'gone' => ['value' => '', 'lifetime' => 0],
        ]);

        $result = CookieSerializer::bridge($globalResp, $response);
        $header = $result->getHeader('Set-Cookie')[0];

        $this->assertStringStartsWith('gone=;', $header);
        $this->assertStringContainsString('; Max-Age=0', $header);
    }

    public function testBridgeSkipsNullValueCookie(): void
    {
        $response = Psr17::factory()->createResponse(200);
        $globalResp = $this->globalResponse([
            'skip' => ['value' => null, 'lifetime' => 0],
        ]);

        $result = CookieSerializer::bridge($globalResp, $response);

        $this->assertSame([], $result->getHeader('Set-Cookie'));
    }

    public function testBridgeIncludesDomainSecureHttponlyAndSamesite(): void
    {
        $response = Psr17::factory()->createResponse(200);
        $globalResp = $this->globalResponse([
            'full' => [
                'value' => 'v',
                'lifetime' => 0,
                'path' => '/app',
                'domain' => 'example.com',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'strict',
            ],
        ]);

        $result = CookieSerializer::bridge($globalResp, $response);
        $header = $result->getHeader('Set-Cookie')[0];

        $this->assertStringContainsString('; Path=/app', $header);
        $this->assertStringContainsString('; Domain=example.com', $header);
        $this->assertStringContainsString('; Secure', $header);
        $this->assertStringContainsString('; HttpOnly', $header);
        $this->assertStringContainsString('; SameSite=Strict', $header);
    }

    public function testBridgeSkipsNonArrayCookieEntryWithoutError(): void
    {
        $response = Psr17::factory()->createResponse(200);
        $globalResp = $this->globalResponse([
            'malformed' => 'not-an-array',
        ]);

        $result = CookieSerializer::bridge($globalResp, $response);

        $this->assertSame([], $result->getHeader('Set-Cookie'));
    }

    public function testBridgeSkipsCookieWithInvalidLifetimeTypeWithoutError(): void
    {
        $response = Psr17::factory()->createResponse(200);
        $globalResp = $this->globalResponse([
            'bad' => ['value' => 'v', 'lifetime' => ['nested' => 'array']],
        ]);

        $result = CookieSerializer::bridge($globalResp, $response);

        $this->assertSame([], $result->getHeader('Set-Cookie'));
    }

    public function testBridgeSkipsCookieWithUnstringableValueWithoutError(): void
    {
        $response = Psr17::factory()->createResponse(200);
        $globalResp = $this->globalResponse([
            'bad' => ['value' => new stdClass(), 'lifetime' => 0],
        ]);

        $result = CookieSerializer::bridge($globalResp, $response);

        $this->assertSame([], $result->getHeader('Set-Cookie'));
    }

    public function testBridgeDoesNotDuplicateIdenticalSetCookieHeaders(): void
    {
        $response = Psr17::factory()->createResponse(200);
        $globalResp = $this->globalResponse([
            'a' => ['value' => 'x', 'lifetime' => 0],
        ]);

        $once = CookieSerializer::bridge($globalResp, $response);
        $twice = CookieSerializer::bridge($globalResp, $once);

        $this->assertCount(1, $twice->getHeader('Set-Cookie'));
    }

    public function testBridgeSwallowsExceptionFromGetCookies(): void
    {
        $response = Psr17::factory()->createResponse(200);
        $globalResp = new class {
            /** @return array<string, mixed> */
            public function getCookies(): array
            {
                throw new RuntimeException('boom');
            }
        };

        $result = CookieSerializer::bridge($globalResp, $response);

        $this->assertSame([], $result->getHeader('Set-Cookie'));
    }
}
