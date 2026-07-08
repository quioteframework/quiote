<?php
use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Middleware\ContentNegotiationMiddleware;

class ContentNegotiationMiddlewareTest extends TestCase
{
    private function negotiate(ContentNegotiationMiddleware $mw, ServerRequestInterface $req): ServerRequestInterface
    {
        $final = new class implements RequestHandlerInterface { public ServerRequestInterface $last; public function handle(ServerRequestInterface $r): ResponseInterface { $this->last = $r; return new Psr7Response(200); } };
        $mw->process($req, $final);
        return $final->last;
    }

    public function testFormatQueryParamIgnoredNow(): void
    {
        $mw = new ContentNegotiationMiddleware();
        $req = new ServerRequest('GET','/foo?format=json');
	$handled = $this->negotiate($mw,$req);
        // Library sets a default (likely html) because Accept missing; we ignore query param
        $this->assertSame('html',$handled->getAttribute('output_type'));
    }

    public function testAcceptHeaderJsonPreferred(): void
    {
        $mw = new ContentNegotiationMiddleware();
        $req = (new ServerRequest('GET','/foo'))->withHeader('Accept','application/json, text/html;q=0.8');
    $handled = $this->negotiate($mw,$req);
        $this->assertSame('json',$handled->getAttribute('output_type'));
    }

    public function testRouteAttributePreserved(): void
    {
        $mw = new ContentNegotiationMiddleware();
        $req = (new ServerRequest('GET','/foo'))->withAttribute('output_type','xml')->withHeader('Accept','application/json');
    $handled = $this->negotiate($mw,$req);
        $this->assertSame('xml',$handled->getAttribute('output_type'));
    }

    public function testExtensionIgnoredInFavorOfAcceptHeader(): void
    {
        $mw = new ContentNegotiationMiddleware();
        $req = (new ServerRequest('GET','/report.xml'))->withHeader('Accept','application/json');
	$handled = $this->negotiate($mw,$req);
        // URL extension is irrelevant; Accept header wins
        $this->assertSame('json',$handled->getAttribute('output_type'));
    }

    public function testWildcardAccept(): void
    {
        $mw = new ContentNegotiationMiddleware();
        $req = (new ServerRequest('GET','/foo'))->withHeader('Accept','*/*');
    $handled = $this->negotiate($mw,$req);
        $this->assertNotNull($handled->getAttribute('output_type'));
    }

    public function testNoHintsDefaultsToHtml(): void
    {
        $mw = new ContentNegotiationMiddleware();
        $req = new ServerRequest('GET','/foo');
	$handled = $this->negotiate($mw,$req);
        $this->assertSame('html',$handled->getAttribute('output_type'));
    }

    public function testBrowserAcceptFastPathsToHtml(): void
    {
        // Typical browser Accept -- leads with text/html; the fast path resolves
        // straight to html without invoking the negotiator.
        $mw = new ContentNegotiationMiddleware();
        $req = (new ServerRequest('GET','/foo'))->withHeader('Accept','text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8');
        $handled = $this->negotiate($mw,$req);
        $this->assertSame('html',$handled->getAttribute('output_type'));
    }

    public function testAssetAcceptNegotiatesToHtmlNotAnAssetFormat(): void
    {
        // A request whose Accept only names asset types negotiates against the
        // narrowed action-output set (no image/font/video), so it resolves to
        // the wildcard default (html) rather than an output type no action emits.
        $mw = new ContentNegotiationMiddleware();
        $req = (new ServerRequest('GET','/foo'))->withHeader('Accept','image/png,image/webp,*/*');
        $handled = $this->negotiate($mw,$req);
        $this->assertSame('html',$handled->getAttribute('output_type'));
    }

    public function testExplicitJsonStillNegotiatesViaNarrowedSet(): void
    {
        $mw = new ContentNegotiationMiddleware();
        $req = (new ServerRequest('GET','/foo'))->withHeader('Accept','application/json');
        $handled = $this->negotiate($mw,$req);
        $this->assertSame('json',$handled->getAttribute('output_type'));
    }

    public function testQualityParametersRespected(): void
    {
        $mw = new ContentNegotiationMiddleware();
        $req = (new ServerRequest('GET','/foo'))->withHeader('Accept','application/json;q=0.5, text/html;q=0.9');
        $handled = $this->negotiate($mw,$req);
        // HTML has higher quality (0.9 > 0.5), so it wins
        $this->assertSame('html',$handled->getAttribute('output_type'));
    }

    public function testQualityParametersHighJsonWins(): void
    {
        $mw = new ContentNegotiationMiddleware();
        $req = (new ServerRequest('GET','/foo'))->withHeader('Accept','application/json;q=0.9, text/html;q=0.5');
        $handled = $this->negotiate($mw,$req);
        // JSON has higher quality (0.9 > 0.5), so it wins
        $this->assertSame('json',$handled->getAttribute('output_type'));
    }
}
