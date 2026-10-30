<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Quiote\DevOverlay\DevOverlayConfig;
use Quiote\DevOverlay\Http\OverlayEndpoint;
use Quiote\DevOverlay\Report\ExceptionReportStoreInterface;

final class OverlayEndpointTest extends TestCase
{
    private const string ID = '0123456789abcdef0123456789abcdef';

    private function endpoint(?string $scriptFile = null): OverlayEndpoint
    {
        $store = new class implements ExceptionReportStoreInterface {
            public function put(string $id, array $report): void
            {
            }

            public function get(string $id): ?array
            {
                return $id === '0123456789abcdef0123456789abcdef' ? ['id' => $id, 'status' => 500] : null;
            }
        };
        $factory = new Psr17Factory();

        return $scriptFile === null
            ? new OverlayEndpoint($store, $factory, $factory)
            : new OverlayEndpoint($store, $factory, $factory, $scriptFile);
    }

    private static function config(): DevOverlayConfig
    {
        return new DevOverlayConfig(active: true, path: '/_quiote/overlay', editorUrl: '');
    }

    public function testIgnoresRequestsOutsideItsPath(): void
    {
        $this->assertNull($this->endpoint()->serve(new ServerRequest('GET', '/orders'), self::config()));
        $this->assertNull($this->endpoint()->serve(new ServerRequest('GET', '/_quiote/overlayish'), self::config()));
    }

    public function testServesTheScript(): void
    {
        $response = $this->endpoint()->serve(new ServerRequest('GET', '/_quiote/overlay/overlay.js'), self::config());

        $this->assertNotNull($response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('text/javascript', $response->getHeaderLine('Content-Type'));
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('X-Quiote-Exception', (string) $response->getBody());
    }

    public function testServesAStoredReportAsJson(): void
    {
        $response = $this->endpoint()->serve(new ServerRequest('GET', '/_quiote/overlay/' . self::ID), self::config());

        $this->assertNotNull($response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame(['id' => self::ID, 'status' => 500], json_decode((string) $response->getBody(), true));
    }

    public function testAnUnknownReportIsAProblem404(): void
    {
        $response = $this->endpoint()->serve(new ServerRequest('GET', '/_quiote/overlay/' . str_repeat('f', 32)), self::config());

        $this->assertNotNull($response);
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
    }

    public function testANonGetRequestIsA405(): void
    {
        $response = $this->endpoint()->serve(new ServerRequest('POST', '/_quiote/overlay/' . self::ID), self::config());

        $this->assertNotNull($response);
        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('GET, HEAD', $response->getHeaderLine('Allow'));
    }

    public function testHonoursAConfiguredPath(): void
    {
        $config = new DevOverlayConfig(active: true, path: '/dev', editorUrl: '');

        $this->assertNull($this->endpoint()->serve(new ServerRequest('GET', '/_quiote/overlay/overlay.js'), $config));
        $this->assertNotNull($this->endpoint()->serve(new ServerRequest('GET', '/dev/overlay.js'), $config));
    }

    public function testAMissingScriptFileThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/could not be read/');
        $this->endpoint('/nonexistent/overlay.js')->serve(new ServerRequest('GET', '/_quiote/overlay/overlay.js'), self::config());
    }
}
