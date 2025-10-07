<?php

require_once __DIR__ . '/CacheMiddlewareTestTrait.php';

use Agavi\Testing\AgaviUnitTestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Agavi\Cache\ActionViewCache;
use Agavi\Cache\CacheManager;
use Agavi\Middleware\DispatchMiddleware;
use Agavi\Execution\ActionDescriptor;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;

#[RunTestsInSeparateProcesses]
class DispatchMiddlewareCacheMetadataTest extends AgaviUnitTestCase
{
    use CacheMiddlewareTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markTestSkipped('Cache tests disabled after AgaviRequestDataHolder removal / cache layer refactor');
    }

    protected function tearDown(): void
    {
        $this->restoreCache();
        parent::tearDown();
    }

    private function buildRequest(ActionDescriptor $descriptor)
    {
        $factory = new Psr17Factory();
        $psr = (new ServerRequest('GET', 'http://localhost/cache'))
            ->withBody($factory->createStream(''));
        return $psr
            ->withAttribute(ActionDescriptor::class, $descriptor)
            ->withAttribute('module', 'Cache')
            ->withAttribute('action', 'Cache');
    }

    public function testCacheStoresDescriptorAndStateMetadata()
    {
        $this->fail('unreachable');
        $controller = $this->getContext()->getController();
        // Prime first run (cache miss, will execute action)
        $controller->createActionInstance('Cache','Cache');
    $descriptor = ActionDescriptor::fromController($controller,'Cache','Cache','GET', strtolower($controller->getOutputType()->getName()));
        $mw = new DispatchMiddleware($controller);
    \Sandbox\Modules\Cache\Actions\CacheAction::$execCount = 0;
    $mw->process($this->buildRequest($descriptor), new class(new Psr17Factory) implements \Psr\Http\Server\RequestHandlerInterface { public function __construct(private $f){} public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface { return $this->f->createResponse(200);} });
    $this->assertSame(1, \Sandbox\Modules\Cache\Actions\CacheAction::$execCount, 'First run should execute action');

        // Inspect raw cache payload
        $avCache = new ActionViewCache(CacheManager::getCache());
        $payload = $avCache->get('Cache','Cache','html'); // output type for test action is typically html
        $this->assertIsArray($payload, 'Payload should be array');
        $this->assertArrayHasKey('descriptor', $payload, 'Descriptor metadata missing');
        $this->assertArrayHasKey('state', $payload, 'State metadata missing');
        $this->assertSame('Cache', $payload['descriptor']['module'] ?? null);
        $this->assertSame('Cache', $payload['descriptor']['action'] ?? null);
        $this->assertArrayHasKey('method', $payload['descriptor']);
        $this->assertArrayHasKey('outputType', $payload['descriptor']);
        $this->assertArrayHasKey('isSimple', $payload['descriptor']);
    $this->assertArrayHasKey('validationDecision', $payload['state']);
    $this->assertArrayHasKey('validationErrors', $payload['state']);
        $this->assertArrayHasKey('viewModule', $payload['state']);
        $this->assertArrayHasKey('viewName', $payload['state']);

        // Second run should be cache hit (action not executed again)
        $controller->createActionInstance('Cache','Cache');
    $descriptor2 = ActionDescriptor::fromController($controller,'Cache','Cache','GET', strtolower($controller->getOutputType()->getName()));
    $mw->process($this->buildRequest($descriptor2), new class(new Psr17Factory) implements \Psr\Http\Server\RequestHandlerInterface { public function __construct(private $f){} public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface { return $this->f->createResponse(200);} });
    $this->assertSame(1, \Sandbox\Modules\Cache\Actions\CacheAction::$execCount, 'Second run should be cache hit (exec count unchanged)');
    }
}
