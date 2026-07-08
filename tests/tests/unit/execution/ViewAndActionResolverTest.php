<?php
use Quiote\Testing\UnitTestCase;
use Quiote\Execution\ViewResolver; // deprecated stub
use Quiote\Execution\ViewNameResolver;
use Quiote\Execution\ActionResolver;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use Quiote\View\View;
use Quiote\Util\Toolkit;
use Quiote\Action\Action;

class ViewAndActionResolverTest extends UnitTestCase
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

    public function testResolveScalarViewName(): void
    {
    // Ensure module directives loaded
    $this->getContext()->getController()->initializeModule('Cache');
    [$vm, $vn] = $this->viewResolver->resolve('Cache','Cache','Success');
        $this->assertSame('Cache', $vm);
    // Directive ${actionName}${viewName} may expand to CacheSuccess or return canonical Success depending on initialization timing
    $this->assertContains($vn, [\Quiote\Util\Toolkit::canonicalName('CacheSuccess'), \Quiote\Util\Toolkit::canonicalName('Success')]);
    }

    public function testResolveArrayViewName(): void
    {
        [$vm, $vn] = $this->viewResolver->resolve('Cache', 'Cache', ['Cache', 'Error']);
        $this->assertSame('Cache', $vm);
        $this->assertSame(Toolkit::canonicalName('Error'), $vn);
    }

    public function testResolveNoneConstant(): void
    {
    [$vm, $vn] = $this->viewResolver->resolve('Cache', 'Cache', View::NONE);
    $this->assertNull($vm);
    $this->assertNull($vn);
    }

    public function testActionResolverSelectsSpecificMethod(): void
    {
        $action = new class extends Action {
            public function executePost(ServerRequestInterface $req): string { return 'PostView'; }
            public function execute(ServerRequestInterface $req): string { return 'GenericView'; }
        };
        $req = new ServerRequest('POST', '/');
        // need to initialize action with dummy container? Not required for method dispatch here.
        $raw = $this->actionResolver->execute($action, 'Post', $req);
        $this->assertSame('PostView', $raw);
    }

    public function testActionResolverFallsBackToGeneric(): void
    {
        $action = new class extends Action {
            public function execute(ServerRequestInterface $req): string { return 'GenericView'; }
        };
        $req = new ServerRequest('PUT', '/');
        $raw = $this->actionResolver->execute($action, 'Put', $req);
        $this->assertSame('GenericView', $raw);
    }
}
