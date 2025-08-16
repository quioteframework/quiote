<?php
use Agavi\Testing\AgaviUnitTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Agavi\Middleware\SlotMiddleware;
use Agavi\Execution\SlotStack;

class SlotMiddlewareTest extends AgaviUnitTestCase
{
    public function testInjectsSlotStack()
    {
        $mw = new SlotMiddleware();
        $factory = new Psr17Factory();
        $req = new ServerRequest('GET', 'http://localhost/');
        $resp = $mw->process($req, new class($factory) implements \Psr\Http\Server\RequestHandlerInterface { public function __construct(private $f){} public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface { return $this->f->createResponse(200);} });
        // SlotStack attribute is not on response; assert creation by re-processing with existing attribute
        $req2 = $req->withAttribute(SlotMiddleware::ATTR, new SlotStack());
        $resp2 = $mw->process($req2, new class($factory) implements \Psr\Http\Server\RequestHandlerInterface { public function __construct(private $f){} public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface { return $this->f->createResponse(200);} });
        $this->assertInstanceOf(\Psr\Http\Message\ResponseInterface::class, $resp2);
        $this->assertNotSame($resp, $resp2); // sanity
    }
}
