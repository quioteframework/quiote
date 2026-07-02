<?php
use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\ServerRequest;
use Quiote\Exception\Rendering\SafeRenderer;

final class SafeRendererTest extends TestCase
{
    public function testHtmlResponseHidesExceptionDetail(): void
    {
        $renderer = new SafeRenderer();
        $req = new ServerRequest('GET', 'http://localhost/');
        $resp = $renderer->render(new \RuntimeException('super secret db password leaked here'), $req, 500, null);

        $this->assertSame(500, $resp->getStatusCode());
        $this->assertStringContainsString('text/html', $resp->getHeaderLine('Content-Type'));
        $this->assertFalse($resp->hasHeader('X-Quiote-Error-Type'));
        $body = (string) $resp->getBody();
        $this->assertStringNotContainsString('secret db password', $body);
        $this->assertStringNotContainsString('RuntimeException', $body);
        $this->assertStringContainsString('Internal Server Error', $body);
    }

    public function testHtmlResponseIncludesCorrelationIdWhenPresent(): void
    {
        $renderer = new SafeRenderer();
        $req = new ServerRequest('GET', 'http://localhost/');
        $resp = $renderer->render(new \RuntimeException('boom'), $req, 500, 'abc-123');

        $body = (string) $resp->getBody();
        $this->assertStringContainsString('abc-123', $body);
    }

    public function testJsonResponseShapeAndNoLeakage(): void
    {
        $renderer = new SafeRenderer();
        $req = (new ServerRequest('GET', 'http://localhost/'))->withHeader('Accept', 'application/json');
        $resp = $renderer->render(new \RuntimeException('super secret'), $req, 400, 'cid-1');

        $this->assertSame(400, $resp->getStatusCode());
        $this->assertStringContainsString('application/json', $resp->getHeaderLine('Content-Type'));
        $this->assertFalse($resp->hasHeader('X-Quiote-Error-Type'));

        $payload = json_decode((string) $resp->getBody(), true);
        $this->assertSame(['error', 'status', 'correlation_id'], array_keys($payload));
        $this->assertSame('Request Error', $payload['error']);
        $this->assertSame(400, $payload['status']);
        $this->assertSame('cid-1', $payload['correlation_id']);
    }

    public function testJsonResponseOmitsCorrelationIdWhenAbsent(): void
    {
        $renderer = new SafeRenderer();
        $req = (new ServerRequest('GET', 'http://localhost/'))->withHeader('Accept', 'application/json');
        $resp = $renderer->render(new \RuntimeException('boom'), $req, 500, null);

        $payload = json_decode((string) $resp->getBody(), true);
        $this->assertSame(['error', 'status'], array_keys($payload));
        $this->assertSame('Internal Server Error', $payload['error']);
    }

    public function testPlainTextResponseIsGenericWithCorrelationId(): void
    {
        $renderer = new SafeRenderer();
        $req = (new ServerRequest('GET', 'http://localhost/'))->withHeader('Accept', 'text/plain');
        $resp = $renderer->render(new \RuntimeException('super secret'), $req, 500, 'cid-2');

        $this->assertStringContainsString('text/plain', $resp->getHeaderLine('Content-Type'));
        $body = (string) $resp->getBody();
        $this->assertSame("Internal error\nCorrelation-Id: cid-2", $body);
    }
}
