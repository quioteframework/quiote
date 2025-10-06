<?php

use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\UploadedFile;
use Agavi\Request\Psr7RequestTrait;

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

    private function makeHarness()
    {
        return new class {
            use Psr7RequestTrait; // expose protected methods via wrappers
            public function gp($r,$n,$d=null){return $this->getRequestParam($r,$n,$d);}    
            public function gps($r,$s=null){return $this->getRequestParams($r,$s);}         
            public function wop($r,$n,$s=null){return $this->withoutParameter($r,$n,$s);}  
        };
    }

    public function testGetRequestParamPrecedence()
    {
        $req = $this->buildRequest();
        $h = $this->makeHarness();
        $this->assertSame('BODY', $h->gp($req, 'dup'));
        $this->assertSame('B', $h->gp($req, 'bodyOnly'));
        $this->assertSame('Q', $h->gp($req, 'qOnly'));
        $this->assertSame('C', $h->gp($req, 'cOnly'));
        $this->assertNull($h->gp($req, 'missing'));
    }

    public function testGetRequestParamsAll()
    {
        $req = $this->buildRequest();
        $h = $this->makeHarness();
        $all = $h->gps($req);
        $this->assertArrayHasKey('dup', $all);
        $this->assertArrayHasKey('qOnly', $all);
        $this->assertArrayHasKey('bodyOnly', $all);
        $this->assertArrayHasKey('cOnly', $all);
    }

    public function testGetRequestParamsSources()
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

    public function testWithoutParameterRemovals()
    {
        $req = $this->buildRequest();
        $h = $this->makeHarness();
        $req2 = $h->wop($req, 'bodyOnly');
        $this->assertNotSame($req, $req2);
        $this->assertNull($h->gp($req2, 'bodyOnly'));
        $req3 = $h->wop($req, 'dup', 'cookies');
        $this->assertSame('BODY', $h->gp($req3, 'dup')); // cookie removal only
        $req4 = $h->wop($req, 'dup', 'headers');
        $this->assertSame('BODY', $h->gp($req4, 'dup'));
        $req5 = $h->wop($req, 'dup', 'parameters');
        // body value removed, falls back to query value
        $this->assertSame('QUERY', $h->gp($req5, 'dup'));
    }
}
