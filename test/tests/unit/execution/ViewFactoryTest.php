<?php

use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Execution\ViewFactory;
use Agavi\Execution\ImmutableViewInitContext; // implicitly used
use Agavi\Execution\ActionDescriptor;
use Agavi\Execution\ExecutionState;
use Agavi\Execution\ActionExecutor as Executor;
use Nyholm\Psr7\ServerRequest;

class ViewFactoryTest extends AgaviUnitTestCase
{
    private function makeFactory(): ViewFactory
    {
        return new ViewFactory($this->getContext()->getController());
    }

    public function testCreateSimpleViewWithAttributesSnapshot()
    {
        // Use existing sandbox Cache/Cache action (simple) which returns Success view
        $controller = $this->getContext()->getController();
        $descriptor = ActionDescriptor::fromController($controller,'Cache','Cache','GET','html');
    $req = (new ServerRequest('GET', '/'))->withQueryParams(['foo' => 'bar']);
        $execState = new ExecutionState();
        $executor = new Executor($controller);
    $ctx = $executor->execute($descriptor, $req, $execState);
    $this->assertSame('Cache', $ctx->viewModuleName);
    // Canonical view name resolved to CacheSuccess (module + action + view segment)
    $this->assertSame('CacheSuccess', $ctx->viewName);
        $this->assertArrayHasKey('foo', $ctx->actionAttributes, 'Attribute snapshot should contain foo');
        // Now re-create the view via factory to ensure deterministic creation works standalone
        $factory = $this->makeFactory();
    $view = $factory->create($ctx->viewModuleName, $ctx->viewName, $descriptor->module, $descriptor->action, $descriptor->outputType, $this->getContext()->getRequest(), $ctx->actionAttributes);
        $this->assertNotNull($view, 'ViewFactory should create view instance');
        $this->assertSame($ctx->viewModuleName, $view->getContainer()->getViewModuleName());
    $this->assertSame($ctx->viewName, $view->getContainer()->getViewName());
    }

    public function testCreateMissingViewReturnsNull()
    {
        $factory = $this->makeFactory();
        $view = $factory->create('Cache','__DoesNotExist__','Cache','Cache','html',null,[]);
        $this->assertNull($view, 'Missing view should return null');
    }
}
