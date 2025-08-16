<?php

use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Config\AgaviConfig;
use Agavi\Cache\CacheManager;
use Agavi\Middleware\DispatchMiddleware;
use Agavi\Http\PsrServerRequestAdapter;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Stream;
use Agavi\Execution\ExecutionState;
use Agavi\Execution\ActionDescriptor;

class DispatchMiddlewareContextSimpleTest extends AgaviUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AgaviConfig::set('core.cache_dir', sys_get_temp_dir() . '/agavi_ctx_simple_cache_test');
    $dir = AgaviConfig::get('core.cache_dir');
    if(!is_dir($dir)) { @mkdir($dir, 0777, true); }
        CacheManager::reset();
        putenv('AGAVI_DISPATCH_CONTEXT=1');
        putenv('AGAVI_DISPATCH_CONTEXT_SIMPLE=1');
        $this->getContext()->getController()->initializeModule('Cache');
    }

    private function buildPsr(): \Psr\Http\Message\ServerRequestInterface
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
            ->withAttribute('module','Cache')
            ->withAttribute('action','Cache')
            ->withAttribute('output_type','html')
            ->withAttribute(ActionDescriptor::class, ActionDescriptor::fromController($this->getContext()->getController(),'Cache','Cache','GET','html'));
    }

    public function testSimpleActionExecutedViaActionExecutor()
    {
    $controller = $this->getContext()->getController();
    $controller->createActionInstance('Cache','Cache'); // ensure module loaded
        $mw = new DispatchMiddleware($controller);
        $state = new ExecutionState(false,false,null,null,[],false);
        $handler = new class(new Psr17Factory) implements \Psr\Http\Server\RequestHandlerInterface { public function __construct(private $f){} public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface { return $this->f->createResponse(200);} };
    $resp = $mw->process($this->buildPsr()->withAttribute(ExecutionState::class,$state), $handler);
    $body = (string)$resp->getBody();
    $this->assertStringContainsString('CACHE_HTML', $body, 'Expected HTML view output rendered via ActionExecutor path');
        $this->assertNotNull($state->viewName, 'View name should be set in execution state');
    }
}
