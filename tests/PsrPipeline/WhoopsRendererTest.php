<?php
use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\ServerRequest;
use Quiote\Exception\Rendering\WhoopsRenderer;

final class WhoopsRendererTest extends TestCase
{
    public function testHtmlResponseIncludesExceptionDetail(): void
    {
        $renderer = new WhoopsRenderer();
        $req = new ServerRequest('GET', 'http://localhost/');
        $resp = $renderer->render(new \RuntimeException('very specific marker message'), $req, 500, null);

        $this->assertSame(500, $resp->getStatusCode());
        $this->assertStringContainsString('text/html', $resp->getHeaderLine('Content-Type'));
        $body = (string) $resp->getBody();
        $this->assertStringContainsString('very specific marker message', $body);
        $this->assertStringContainsString('RuntimeException', $body);
    }

    public function testHtmlResponseIncludesCorrelationId(): void
    {
        $renderer = new WhoopsRenderer();
        $req = new ServerRequest('GET', 'http://localhost/');
        $resp = $renderer->render(new \RuntimeException('boom'), $req, 500, 'cid-42');

        $body = (string) $resp->getBody();
        $this->assertStringContainsString('cid-42', $body);
    }

    public function testJsonResponseIncludesExceptionDetail(): void
    {
        $renderer = new WhoopsRenderer();
        $req = (new ServerRequest('GET', 'http://localhost/'))->withHeader('Accept', 'application/json');
        $resp = $renderer->render(new \RuntimeException('json marker message'), $req, 400, null);

        $this->assertSame(400, $resp->getStatusCode());
        $this->assertStringContainsString('application/json', $resp->getHeaderLine('Content-Type'));
        $payload = json_decode((string) $resp->getBody(), true);
        $this->assertStringContainsString('json marker message', $payload['error']['message']);
        $this->assertSame('RuntimeException', $payload['error']['type']);
    }

    public function testPlainTextResponseIncludesExceptionDetail(): void
    {
        $renderer = new WhoopsRenderer();
        $req = (new ServerRequest('GET', 'http://localhost/'))->withHeader('Accept', 'text/plain');
        $resp = $renderer->render(new \RuntimeException('plaintext marker message'), $req, 500, null);

        $this->assertStringContainsString('text/plain', $resp->getHeaderLine('Content-Type'));
        $body = (string) $resp->getBody();
        $this->assertStringContainsString('plaintext marker message', $body);
    }

    public function testDoesNotExitOrEchoDirectly(): void
    {
        $renderer = new WhoopsRenderer();
        $req = new ServerRequest('GET', 'http://localhost/');

        ob_start();
        $resp = $renderer->render(new \RuntimeException('no echo leakage'), $req, 500, null);
        $leaked = ob_get_clean();

        $this->assertSame('', $leaked, 'WhoopsRenderer must not echo output directly; the process may be a persistent worker');
        $this->assertNotEmpty((string) $resp->getBody());
    }
}
