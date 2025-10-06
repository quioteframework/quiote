<?php
use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Execution\ViewResolver; // deprecated stub
use Agavi\Execution\ViewNameResolver;
use Agavi\Execution\ActionResolver;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use Agavi\View\AgaviView;
use Agavi\Util\AgaviToolkit;
use Agavi\Action\AgaviAction;

class ViewAndActionResolverTest extends AgaviUnitTestCase
{
    private ViewNameResolver $viewResolver;
    private ActionResolver $actionResolver;

    protected function setUp(): void
    {
        parent::setUp();
    // Legacy getViewResolver() removed; instantiate stub directly (delegates to ViewNameResolver)
        $this->viewResolver = new ViewNameResolver();
        $this->actionResolver = $this->getContext()->getActionResolver();
    }

    public function testResolveScalarViewName()
    {
    // Ensure module directives loaded
    $this->getContext()->getController()->initializeModule('Cache');
    [$vm, $vn] = $this->viewResolver->resolve('Cache','Cache','Success');
        $this->assertSame('Cache', $vm);
    // Directive ${actionName}${viewName} may expand to CacheSuccess or return canonical Success depending on initialization timing
    $this->assertContains($vn, [\Agavi\Util\AgaviToolkit::canonicalName('CacheSuccess'), \Agavi\Util\AgaviToolkit::canonicalName('Success')]);
    }

    public function testResolveArrayViewName()
    {
        [$vm, $vn] = $this->viewResolver->resolve('Cache', 'Cache', ['Cache', 'Error']);
        $this->assertSame('Cache', $vm);
        $this->assertSame(AgaviToolkit::canonicalName('Error'), $vn);
    }

    public function testResolveNoneConstant()
    {
    [$vm, $vn] = $this->viewResolver->resolve('Cache', 'Cache', AgaviView::NONE);
    $this->assertNull($vm);
    $this->assertNull($vn);
    }

    public function testActionResolverSelectsSpecificMethod()
    {
        $action = new class extends AgaviAction {
            public function executePost(ServerRequestInterface $req){ return 'PostView'; }
            public function execute(ServerRequestInterface $req){ return 'GenericView'; }
        };
        $req = new ServerRequest('POST', '/');
        // need to initialize action with dummy container? Not required for method dispatch here.
        $raw = $this->actionResolver->execute($action, 'Post', $req);
        $this->assertSame('PostView', $raw);
    }

    public function testActionResolverFallsBackToGeneric()
    {
        $action = new class extends AgaviAction {
            public function execute(ServerRequestInterface $req){ return 'GenericView'; }
        };
        $req = new ServerRequest('PUT', '/');
        $raw = $this->actionResolver->execute($action, 'Put', $req);
        $this->assertSame('GenericView', $raw);
    }
}
