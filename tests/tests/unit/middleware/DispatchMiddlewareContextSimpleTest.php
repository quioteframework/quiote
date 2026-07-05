<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Config\Config;
use Quiote\Cache\CacheManager;
use Quiote\Middleware\DispatchMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Quiote\Execution\ExecutionState;
use Quiote\Execution\ActionDescriptor;

class DispatchMiddlewareContextSimpleTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('core.cache_dir', sys_get_temp_dir() . '/quiote_ctx_simple_cache_test');
    $dir = Config::getString('core.cache_dir');
    if(!is_dir($dir)) { @mkdir($dir, 0777, true); }
        CacheManager::reset();
        putenv('QUIOTE_DISPATCH_CONTEXT=1');
        putenv('QUIOTE_DISPATCH_CONTEXT_SIMPLE=1');
        $this->getContext()->getController()->initializeModule('Cache');
    }

    private function buildPsr(): \Psr\Http\Message\ServerRequestInterface
    {
        $controller = $this->getContext()->getController();
        $descriptor = ActionDescriptor::fromController($controller,'Cache','Cache','GET','html');
        return (new ServerRequest('GET', 'http://localhost/cache'))
            ->withAttribute('module','Cache')
            ->withAttribute('action','Cache')
            ->withAttribute('output_type','html')
            ->withAttribute(ActionDescriptor::class, $descriptor);
    }

    public function testSimpleActionExecutedViaActionExecutor()
    {
    $controller = $this->getContext()->getController();
    $controller->createActionInstance('Cache','Cache'); // ensure module loaded
        $mw = new DispatchMiddleware($controller);
    $state = new ExecutionState();
        $handler = new class(new Psr17Factory) implements \Psr\Http\Server\RequestHandlerInterface { public function __construct(private $f){} public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface { return $this->f->createResponse(200);} };
    $resp = $mw->process($this->buildPsr()->withAttribute(ExecutionState::class,$state), $handler);
    $body = (string)$resp->getBody();
    $this->assertStringContainsString('CACHE_HTML', $body, 'Expected HTML view output rendered via ActionExecutor path');
        $this->assertNotNull($state->viewName, 'View name should be set in execution state');
    }
}
