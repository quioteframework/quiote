<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Request\RequestUrl;

final class RequestUrlFromServerParamsTest extends TestCase
{
    /** @param array<string, string> $server */
    private static function url(array $server): RequestUrl
    {
        return RequestUrl::fromServerParams($server + ['REQUEST_URI' => '/orders']);
    }

    /** The proxy terminated TLS and named no port: the client used 443, not the hop's 80. */
    public function testAForwardedSchemeWithoutAPortUsesThatSchemesDefault(): void
    {
        $url = self::url(['HTTP_HOST' => 'localhost', 'SERVER_PORT' => '80', 'HTTP_X_FORWARDED_PROTO' => 'https']);

        $this->assertSame('https', $url->scheme);
        $this->assertSame(443, $url->port);
        $this->assertSame('https://localhost/orders', $url->url);
    }

    public function testThePortInTheHopsHostHeaderIsIgnoredToo(): void
    {
        $url = self::url(['HTTP_HOST' => 'localhost:8080', 'SERVER_PORT' => '8080', 'HTTP_X_FORWARDED_PROTO' => 'https']);

        $this->assertSame('localhost', $url->host);
        $this->assertSame(443, $url->port);
    }

    public function testAForwardedPortWins(): void
    {
        $url = self::url([
            'HTTP_HOST' => 'localhost',
            'SERVER_PORT' => '80',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_PORT' => '8443, 443',
        ]);

        $this->assertSame(8443, $url->port);
    }

    public function testANonNumericForwardedPortFallsBackToTheDefault(): void
    {
        $url = self::url([
            'HTTP_HOST' => 'localhost',
            'SERVER_PORT' => '80',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_PORT' => 'tls',
        ]);

        $this->assertSame(443, $url->port);
    }

    public function testWithoutAForwardedSchemeTheServerPortStillCounts(): void
    {
        $url = self::url(['HTTP_HOST' => 'localhost', 'SERVER_PORT' => '8080']);

        $this->assertSame('http', $url->scheme);
        $this->assertSame(8080, $url->port);
    }
}
