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

// Circular conflict test helpers
if(!class_exists('CircularA')) {
    #[AM(phase: 'pre', before: 'circular_b')]
    class CircularA implements MiddlewareInterface { public function __construct(private TestService $svc){} public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface { $this->svc->hits[]='A'; return $handler->handle($request);} }
}
if(!class_exists('CircularB')) {
    #[AM(phase: 'pre', before: 'circular_a')]
    class CircularB implements MiddlewareInterface { public function __construct(private TestService $svc){} public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface { $this->svc->hits[]='B'; return $handler->handle($request);} }
}

class MiddlewarePipelineBuilderTest extends TestCase
{
    public function testEmptyPipelineRunsFinalHandler()
    {
        $factory = new Psr17Factory();
        $final = new class($factory) implements RequestHandlerInterface { public function __construct(private $f){} public function handle(ServerRequestInterface $r): ResponseInterface { return $this->f->createResponse(204);} };
        $pipeline = MiddlewarePipelineBuilder::fromClasses([], $final);
        $resp = $pipeline->handle($factory->createServerRequest('GET','/empty'));
        $this->assertSame(204, $resp->getStatusCode());
    }

    public function testDuplicateLogicalNameUsesLastDefinition()
    {
        $factory = new Psr17Factory();
        $final = new class($factory) implements RequestHandlerInterface { public function __construct(private $f){} public function handle(ServerRequestInterface $r): ResponseInterface { return $this->f->createResponse(200);} };
        $container = new Container();
        $svc = new TestService();
        $container->set(TestService::class, $svc);
        // Provide two entries with same logical key 'alpha' mapping to two different classes (second overwrites)
        $pipeline = MiddlewarePipelineBuilder::fromClasses([
            BuilderTestGamma::class => 'alpha',
            BuilderTestAlpha::class => 'alpha', // expected to override gamma at logical name 'alpha'
        ], $final, container: $container);
        $pipeline->handle($factory->createServerRequest('GET','/dup'));
        // Only alpha should have run, not gamma
        $this->assertSame(['alpha'], $svc->hits);
    }

    public function testBeforeAfterConflictResolutionStable()
    {
        $factory = new Psr17Factory();
        $final = new class($factory) implements RequestHandlerInterface { public function __construct(private $f){} public function handle(ServerRequestInterface $r): ResponseInterface { return $this->f->createResponse(200);} };
        $container = new Container();
        $svc = new TestService();
        $container->set(TestService::class, $svc);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to resolve middleware ordering');
        $pipeline = MiddlewarePipelineBuilder::fromClasses([
            CircularA::class => 'circular_a',
            CircularB::class => 'circular_b',
        ], $final, container: $container);
        $pipeline->handle($factory->createServerRequest('GET','/circular'));
    }
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
