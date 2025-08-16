<?php
use PHPUnit\Framework\TestCase;
use Agavi\Middleware\MiddlewarePipelineBuilder;
use Agavi\Middleware\Attribute\AgaviMiddleware as AM;
use Agavi\DI\Container;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Nyholm\Psr7\Factory\Psr17Factory;

#[AM(phase: 'pre', priority: 50)]
class BuilderTestAlpha implements MiddlewareInterface {
    public function __construct(private TestService $svc) {}
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface { $this->svc->hits[] = 'alpha'; return $handler->handle($request);} }
#[AM(phase: 'pre', before: 'beta')]
class BuilderTestGamma implements MiddlewareInterface { public function __construct(private TestService $svc){} public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface { $this->svc->hits[]='gamma'; return $handler->handle($request);} }
#[AM(phase: 'pre', after: 'alpha', priority: 10)]
class BuilderTestBeta implements MiddlewareInterface { public function __construct(private TestService $svc){} public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface { $this->svc->hits[]='beta'; return $handler->handle($request);} }

class TestService { public array $hits = []; }

class MiddlewarePipelineBuilderTest extends TestCase
{
    public function testBuildFromAnnotatedClassesWithDI()
    {
        $factory = new Psr17Factory();
        $final = new class($factory) implements RequestHandlerInterface { public function __construct(private $f){} public function handle(ServerRequestInterface $r): ResponseInterface { return $this->f->createResponse(200);} };
        $container = new Container();
        $svc = new TestService();
        $container->set(TestService::class, $svc);
        $pipeline = MiddlewarePipelineBuilder::fromClasses([
            BuilderTestAlpha::class => 'alpha',
            BuilderTestBeta::class => 'beta',
            BuilderTestGamma::class => 'gamma',
        ], $final, container: $container);
        $pipeline->handle($factory->createServerRequest('GET','/'));
        // Expected order: alpha (highest priority), beta (after alpha), gamma (before beta) => gamma must shift before beta but cannot precede alpha because no relation; so final: alpha, gamma, beta
        $this->assertEquals(['alpha','gamma','beta'], $svc->hits);
    }
}
