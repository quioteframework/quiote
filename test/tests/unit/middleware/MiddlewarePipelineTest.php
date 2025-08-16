<?php
use PHPUnit\Framework\TestCase;
use Agavi\Middleware\MiddlewarePipeline;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Nyholm\Psr7\Factory\Psr17Factory;

class MiddlewarePipelineTest extends TestCase
{
    public function testOrderingWithPhasesAndPriority()
    {
        $factory = new Psr17Factory();
        $final = new class($factory) implements RequestHandlerInterface {
            public function __construct(private $f) {}
            public function handle(ServerRequestInterface $r): ResponseInterface { return $this->f->createResponse(200); }
        };
        $log = [];
        $mk = function(string $name) use (&$log): MiddlewareInterface {
            return new class($name, $log) implements MiddlewareInterface {
                private string $n; 
                private $logRef; // reference to external array
                public function __construct(string $n, array &$log)
                {
                    $this->n = $n;
                    $this->logRef =& $log; // store by reference
                }
                public function process(ServerRequestInterface $req, RequestHandlerInterface $h): ResponseInterface 
                { 
                    $this->logRef[] = $this->n; 
                    return $h->handle($req);
                }
            };
        };
        $p = new MiddlewarePipeline($final);
        $p->add('A',$mk('A'),'pre', priority:5);
        $p->add('B',$mk('B'),'pre', priority:10); // higher prio => earlier
        $p->add('C',$mk('C'),'post');
        $p->addAfter('A','D',$mk('D')); // after A within same phase
        $resp = $p->handle($factory->createServerRequest('GET','/'));
        $this->assertEquals(200,$resp->getStatusCode());
        $this->assertEquals(['B','A','D','C'],$log);
    }

    public function testBeforeAfterReordering()
    {
        $factory = new Psr17Factory();
        $final = new class($factory) implements RequestHandlerInterface { public function __construct(private $f){} public function handle(ServerRequestInterface $r): ResponseInterface { return $this->f->createResponse(200);} };
        $order = [];
        $mw = function($n) use (&$order) { 
            return new class($n,$order) implements MiddlewareInterface { 
                private string $n; 
                private $orderRef; 
                public function __construct(string $n, array &$o){ $this->n=$n; $this->orderRef =& $o; } 
                public function process(ServerRequestInterface $r, RequestHandlerInterface $h): ResponseInterface { $this->orderRef[]=$this->n; return $h->handle($r);} 
            }; 
        }; 
        $p = new MiddlewarePipeline($final);
        $p->add('one',$mw('one'),'pre');
        $p->add('two',$mw('two'),'pre');
        $p->addBefore('two','onePointFive',$mw('onePointFive'));
        $p->addAfter('two','three',$mw('three'),'pre');
        $p->handle($factory->createServerRequest('GET','/'));
        $this->assertEquals(['one','onePointFive','two','three'],$order);
    }
}
