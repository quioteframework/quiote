<?php

use Agavi\Testing\AgaviUnitTestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Agavi\Config\AgaviConfig;
use Agavi\Cache\CacheManager;
use Agavi\Middleware\DispatchMiddleware;
use Agavi\Http\PsrServerRequestAdapter;
use Agavi\Execution\ActionDescriptor;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Stream;
use Agavi\Cache\ActionViewCache;

#[RunTestsInSeparateProcesses]
class DispatchMiddlewareCacheMetadataTest extends AgaviUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if(!AgaviConfig::get('core.cache_enabled', false)) {
            $this->markTestSkipped('Global cache disabled via core.cache_enabled');
        }
    AgaviConfig::set('core.cache_dir', sys_get_temp_dir() . '/agavi_cache_test');
    $dir = AgaviConfig::get('core.cache_dir'); if(!is_dir($dir)) { @mkdir($dir, 0775, true); }
        CacheManager::reset();
        // Clean PSR cache directory to ensure isolation
        $psrDir = AgaviConfig::get('core.cache_dir') . DIRECTORY_SEPARATOR . 'psr-cache';
        if (is_dir($psrDir)) {
            $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($psrDir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($rii as $file) { $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname()); }
            @rmdir($psrDir);
        }
        $this->getContext()->getController()->initializeModule('Cache');
    }

    private function buildRequest(ActionDescriptor $descriptor)
    {
        $factory = new Psr17Factory();
        $legacyReq = $this->getContext()->getRequest();
        $psr = new PsrServerRequestAdapter(
            $legacyReq,
            $factory->createUri('http://localhost/cache'),
            'GET',
            Stream::create(''),
            [], [], [], [], [], []
        );
        return $psr
            ->withAttribute(ActionDescriptor::class, $descriptor)
            ->withAttribute('module', 'Cache')
            ->withAttribute('action', 'Cache');
    }

    public function testCacheStoresDescriptorAndStateMetadata()
    {
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
        $this->assertArrayHasKey('validationPerformed', $payload['state']);
        $this->assertArrayHasKey('validationSucceeded', $payload['state']);
        $this->assertArrayHasKey('viewModule', $payload['state']);
        $this->assertArrayHasKey('viewName', $payload['state']);

        // Second run should be cache hit (action not executed again)
        $controller->createActionInstance('Cache','Cache');
    $descriptor2 = ActionDescriptor::fromController($controller,'Cache','Cache','GET', strtolower($controller->getOutputType()->getName()));
    $mw->process($this->buildRequest($descriptor2), new class(new Psr17Factory) implements \Psr\Http\Server\RequestHandlerInterface { public function __construct(private $f){} public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface { return $this->f->createResponse(200);} });
    $this->assertSame(1, \Sandbox\Modules\Cache\Actions\CacheAction::$execCount, 'Second run should be cache hit (exec count unchanged)');
    }
}
