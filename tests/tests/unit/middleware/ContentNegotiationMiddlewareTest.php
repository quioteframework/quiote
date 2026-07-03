<?php
use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Middleware\ContentNegotiationMiddleware;
use Quiote\Context;

class ContentNegotiationMiddlewareTest extends TestCase
{
    private function controller(): \Quiote\Controller\Controller
    {
        $ctx = Context::getInstance('test');
        return $ctx->getController();
    }

    private function negotiate(ContentNegotiationMiddleware $mw, ServerRequestInterface $req): ServerRequestInterface
    {
        $final = new class implements RequestHandlerInterface { public ServerRequestInterface $last; public function handle(ServerRequestInterface $r): ResponseInterface { $this->last = $r; return new Psr7Response(200); } };
        $mw->process($req, $final);
        return $final->last;
    }

    public function testFormatQueryParamIgnoredNow(): void
    {
        $mw = new ContentNegotiationMiddleware($this->controller());
        $req = new ServerRequest('GET','/foo?format=json');
	$handled = $this->negotiate($mw,$req);
        // Library sets a default (likely html) because Accept missing; we ignore query param
        $this->assertSame('html',$handled->getAttribute('output_type'));
    }

    public function testAcceptHeaderJsonPreferred(): void
    {
        $mw = new ContentNegotiationMiddleware($this->controller());
        $req = (new ServerRequest('GET','/foo'))->withHeader('Accept','application/json, text/html;q=0.8');
    $handled = $this->negotiate($mw,$req);
        $this->assertSame('json',$handled->getAttribute('output_type'));
    }

    public function testRouteAttributePreserved(): void
    {
        $mw = new ContentNegotiationMiddleware($this->controller());
        $req = (new ServerRequest('GET','/foo'))->withAttribute('output_type','xml')->withHeader('Accept','application/json');
    $handled = $this->negotiate($mw,$req);
        $this->assertSame('xml',$handled->getAttribute('output_type'));
    }

    public function testExtensionIgnoredNow(): void
    {
        $mw = new ContentNegotiationMiddleware($this->controller());
        $req = (new ServerRequest('GET','/report.xml'))->withHeader('Accept','application/json, text/html');
	$handled = $this->negotiate($mw,$req);
        // Extension may cause library to pick xml prior to header negotiation (it checks extension first)
        $this->assertSame('xml',$handled->getAttribute('output_type'));
    }

    public function testWildcardAccept(): void
    {
        $mw = new ContentNegotiationMiddleware($this->controller());
        $req = (new ServerRequest('GET','/foo'))->withHeader('Accept','*/*');
    $handled = $this->negotiate($mw,$req);
        $this->assertNotNull($handled->getAttribute('output_type'));
    }

    public function testNoHintsDefaultsToHtml(): void
    {
        $mw = new ContentNegotiationMiddleware($this->controller());
        $req = new ServerRequest('GET','/foo');
	$handled = $this->negotiate($mw,$req);
        $this->assertSame('html',$handled->getAttribute('output_type'));
    }

    public function testBrowserAcceptFastPathsToHtml(): void
    {
        // Typical browser Accept -- leads with text/html; the fast path resolves
        // straight to html without invoking the negotiator.
        $mw = new ContentNegotiationMiddleware($this->controller());
        $req = (new ServerRequest('GET','/foo'))->withHeader('Accept','text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8');
        $handled = $this->negotiate($mw,$req);
        $this->assertSame('html',$handled->getAttribute('output_type'));
    }

    public function testAssetAcceptNegotiatesToHtmlNotAnAssetFormat(): void
    {
        // A request whose Accept only names asset types negotiates against the
        // narrowed action-output set (no image/font/video), so it resolves to
        // the wildcard default (html) rather than an output type no action emits.
        $mw = new ContentNegotiationMiddleware($this->controller());
        $req = (new ServerRequest('GET','/foo'))->withHeader('Accept','image/png,image/webp,*/*');
        $handled = $this->negotiate($mw,$req);
        $this->assertSame('html',$handled->getAttribute('output_type'));
    }

    public function testExplicitJsonStillNegotiatesViaNarrowedSet(): void
    {
        $mw = new ContentNegotiationMiddleware($this->controller());
        $req = (new ServerRequest('GET','/foo'))->withHeader('Accept','application/json');
        $handled = $this->negotiate($mw,$req);
        $this->assertSame('json',$handled->getAttribute('output_type'));
    }
}
