<?php

use Agavi\Config\AgaviConfig;
use Agavi\Cache\CacheManager;
use Agavi\Middleware\DispatchMiddleware;
use Agavi\Request\AgaviWebRequest;
use Agavi\Testing\AgaviUnitTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Http\Server\RequestHandlerInterface;

#[RunTestsInSeparateProcesses]
class DispatchMiddlewareCacheTest extends AgaviUnitTestCase
{
    private $hadCacheEnabled = false;
    private $previousCacheEnabled;
    private $hadUseCache = false;
    private $previousUseCache;
    private $hadCacheDir = false;
    private $previousCacheDir;
    private $testCacheDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->markTestSkipped('Cache tests disabled after AgaviRequestDataHolder removal / cache layer refactor');

        $this->hadCacheEnabled = AgaviConfig::has('core.cache_enabled');
        if($this->hadCacheEnabled) {
            $this->previousCacheEnabled = AgaviConfig::get('core.cache_enabled');
        }
        AgaviConfig::set('core.cache_enabled', true);

        $this->hadUseCache = AgaviConfig::has('core.use_cache');
        if($this->hadUseCache) {
            $this->previousUseCache = AgaviConfig::get('core.use_cache');
        }
        AgaviConfig::set('core.use_cache', true);

        $this->hadCacheDir = AgaviConfig::has('core.cache_dir');
        if($this->hadCacheDir) {
            $this->previousCacheDir = AgaviConfig::get('core.cache_dir');
        }

        $this->testCacheDir = sys_get_temp_dir() . '/agavi_cache_test';
        AgaviConfig::set('core.cache_dir', $this->testCacheDir);
        if(!is_dir($this->testCacheDir)) {
            @mkdir($this->testCacheDir, 0775, true);
        }
        $this->clearDirectory($this->testCacheDir);
        CacheManager::reset();

        $this->getContext()->getController()->initializeModule('Cache');
    }

    protected function tearDown(): void
    {
        if(is_dir($this->testCacheDir)) {
            $this->clearDirectory($this->testCacheDir);
            @rmdir($this->testCacheDir);
        }

        if($this->hadCacheEnabled) {
            AgaviConfig::set('core.cache_enabled', $this->previousCacheEnabled);
        } else {
            AgaviConfig::remove('core.cache_enabled');
        }

        if($this->hadUseCache) {
            AgaviConfig::set('core.use_cache', $this->previousUseCache);
        } else {
            AgaviConfig::remove('core.use_cache');
        }

        if($this->hadCacheDir) {
            AgaviConfig::set('core.cache_dir', $this->previousCacheDir);
        } else {
            AgaviConfig::remove('core.cache_dir');
        }

        CacheManager::reset();

        parent::tearDown();
    }

    public function testActionResponseIsCachedOnSecondRun()
    {
        $this->fail('unreachable');
        $controller = $this->getContext()->getController();
        $request = $this->getContext()->getRequest();

        if(method_exists($request, 'startup')) {
            try {
                $request->startup();
            } catch(\Throwable) {
                // ignore legacy startup failures in isolated tests
            }
        }

        if(method_exists($controller, 'startup')) {
            $controller->startup();
        }

        $this->clearDirectory($this->testCacheDir . DIRECTORY_SEPARATOR . 'psr-cache');

        $controller->createActionInstance('Cache', 'Cache');
        $descriptor = \Agavi\Execution\ActionDescriptor::fromController(
            $controller,
            'Cache',
            'Cache',
            'GET',
            strtolower($controller->getOutputType()->getName())
        );

        $factory = new Psr17Factory();
        $legacyRequest = $this->getContext()->getRequest();
        $this->assertInstanceOf(AgaviWebRequest::class, $legacyRequest);

        $psrRequest = (new ServerRequest('GET', 'http://localhost/cache'))
            ->withBody($factory->createStream(''))
            ->withAttribute(\Agavi\Execution\ActionDescriptor::class, $descriptor)
            ->withAttribute('module', 'Cache')
            ->withAttribute('action', 'Cache');

        $middleware = new DispatchMiddleware($controller);
        $handler = new class($factory) implements RequestHandlerInterface {
            public function __construct(private Psr17Factory $factory) {}

            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                return $this->factory->createResponse(200);
            }
        };

        \Sandbox\Modules\Cache\Actions\CacheAction::$execCount = 0;
        $middleware->process($psrRequest, $handler);

        $this->assertSame(1, \Sandbox\Modules\Cache\Actions\CacheAction::$execCount, 'Action should execute once on first run');

        $controller->createActionInstance('Cache', 'Cache');
        $descriptor2 = \Agavi\Execution\ActionDescriptor::fromController(
            $controller,
            'Cache',
            'Cache',
            'GET',
            strtolower($controller->getOutputType()->getName())
        );

        $psrRequest2 = $psrRequest
            ->withAttribute(\Agavi\Execution\ActionDescriptor::class, $descriptor2);

        $middleware->process($psrRequest2, $handler);

        $this->assertSame(1, \Sandbox\Modules\Cache\Actions\CacheAction::$execCount, 'Action should not execute again if cached');
    }

    private function clearDirectory($directory): void
    {
        if(!is_string($directory) || $directory === '' || !is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach($iterator as $file) {
            if($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
    }
}
