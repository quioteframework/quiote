<?php
use Quiote\Testing\UnitTestCase;
use Quiote\Execution\ActionResolver;
use Quiote\Action\Action;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

class ActionResolverDefaultViewFallbackTest extends UnitTestCase
{
    private ActionResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = $this->getContext()->getActionResolver();
    }

    public function testExecutesSpecificMethodWhenPresent()
    {
        $action = new class extends Action { public function executeRead(ServerRequestInterface $req){ return 'Specific'; } };
        $req = new ServerRequest('GET', '/');
        $view = $this->resolver->execute($action, 'Read', $req);
        $this->assertSame('Specific', $view);
    }

    public function testFallsBackToGenericExecute()
    {
        $action = new class extends Action { public function execute(ServerRequestInterface $req){ return 'Generic'; } };
        $req = new ServerRequest('POST', '/');
        $view = $this->resolver->execute($action, 'Write', $req);
        $this->assertSame('Generic', $view);
    }

    public function testFallsBackToDefaultViewName()
    {
        $action = new class extends Action { public function getDefaultViewName(){ return 'Fallback'; } };
        $req = new ServerRequest('PATCH', '/');
        $view = $this->resolver->execute($action, 'Patch', $req);
        $this->assertSame('Fallback', $view);
    }

    public function testThrowsWhenNoMethodsOrDefault()
    {
        $action = new class extends Action { public function getDefaultViewName(){ return ''; } };
        $req = new ServerRequest('DELETE', '/');
        $this->expectException(\Quiote\Exception\QuioteException::class);
        $this->resolver->execute($action, 'Delete', $req);
    }
}
