<?php

use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\ServerRequestInterface;
use Quiote\Request\Psr7RequestTrait;

/** Exposes Psr7RequestTrait's protected methods for direct testing. */
final class Psr7RequestTraitHarness
{
    use Psr7RequestTrait;

    public function gp(ServerRequestInterface $r, string $n, mixed $d = null): mixed
    {
        return $this->getRequestParam($r, $n, $d);
    }

    /** @return array<int|string, mixed> */
    public function gps(ServerRequestInterface $r, ?string $s = null): array
    {
        return $this->getRequestParams($r, $s);
    }

    public function wop(ServerRequestInterface $r, string $n, ?string $s = null): ?ServerRequestInterface
    {
        return $this->withoutParameter($r, $n, $s);
    }
}

class Psr7RequestTraitTest extends TestCase
{
    private function buildRequest(): ServerRequest
    {
        $factory = new Nyholm\Psr7\Factory\Psr17Factory();
        $stream = $factory->createStream('');
        $req = new ServerRequest('POST', '/test');
        $req = $req->withParsedBody(['bodyOnly' => 'B', 'dup' => 'BODY'])
                   ->withQueryParams(['qOnly' => 'Q', 'dup' => 'QUERY'])
                   ->withCookieParams(['cOnly' => 'C', 'dup' => 'COOKIE'])
                   ->withHeader('dup', 'HEADER')
                   ->withUploadedFiles(['dup' => new UploadedFile($factory->createStream('filedata'), 8, UPLOAD_ERR_OK, 'f.txt', 'text/plain')]);
        return $req;
    }

    private function makeHarness(): Psr7RequestTraitHarness
    {
        return new Psr7RequestTraitHarness();
    }

    /**
     * withoutParameter() legitimately returns null when the given source
     * doesn't contain the parameter, but every call in this test targets a
     * parameter it knows is present, so a null here means the trait itself
     * is broken rather than something callers should route around.
     */
    private function requireRequest(?ServerRequestInterface $request): ServerRequestInterface
    {
        if ($request === null) {
            throw new \RuntimeException('Expected withoutParameter() to return a modified request.');
        }
        return $request;
    }

    public function testGetRequestParamPrecedence(): void
    {
        $req = $this->buildRequest();
        $h = $this->makeHarness();
        $this->assertSame('BODY', $h->gp($req, 'dup'));
        $this->assertSame('B', $h->gp($req, 'bodyOnly'));
        $this->assertSame('Q', $h->gp($req, 'qOnly'));
        $this->assertSame('C', $h->gp($req, 'cOnly'));
        $this->assertNull($h->gp($req, 'missing'));
    }

    public function testGetRequestParamsAll(): void
    {
        $req = $this->buildRequest();
        $h = $this->makeHarness();
        $all = $h->gps($req);
        $this->assertArrayHasKey('dup', $all);
        $this->assertArrayHasKey('qOnly', $all);
        $this->assertArrayHasKey('bodyOnly', $all);
        $this->assertArrayHasKey('cOnly', $all);
    }

    public function testGetRequestParamsSources(): void
    {
        $req = $this->buildRequest();
        $h = $this->makeHarness();
        $params = $h->gps($req, 'parameters');
        $this->assertArrayHasKey('dup', $params);
        $this->assertArrayHasKey('qOnly', $params);
        $this->assertArrayHasKey('bodyOnly', $params);
        $this->assertArrayNotHasKey('cOnly', $params);
        $headers = $h->gps($req, 'headers');
        $this->assertArrayHasKey('dup', $headers);
        $cookies = $h->gps($req, 'cookies');
        $this->assertArrayHasKey('cOnly', $cookies);
        $files = $h->gps($req, 'files');
        $this->assertArrayHasKey('dup', $files);
        $this->assertSame([], $h->gps($req, 'unknown'));
    }

    public function testWithoutParameterRemovals(): void
    {
        $req = $this->buildRequest();
        $h = $this->makeHarness();
        $req2 = $this->requireRequest($h->wop($req, 'bodyOnly'));
        $this->assertNotSame($req, $req2);
        $this->assertNull($h->gp($req2, 'bodyOnly'));
        $req3 = $this->requireRequest($h->wop($req, 'dup', 'cookies'));
        $this->assertSame('BODY', $h->gp($req3, 'dup')); // cookie removal only
        $req4 = $this->requireRequest($h->wop($req, 'dup', 'headers'));
        $this->assertSame('BODY', $h->gp($req4, 'dup'));
        $req5 = $this->requireRequest($h->wop($req, 'dup', 'parameters'));
        // body value removed, falls back to query value
        $this->assertSame('QUERY', $h->gp($req5, 'dup'));
    }
}
