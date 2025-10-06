<?php
use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Execution\ActionResolver;
use Agavi\Action\AgaviAction;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

class ActionResolverDefaultViewFallbackTest extends AgaviUnitTestCase
{
    private ActionResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = $this->getContext()->getActionResolver();
    }

    public function testExecutesSpecificMethodWhenPresent()
    {
        $action = new class extends AgaviAction { public function executeRead(ServerRequestInterface $req){ return 'Specific'; } };
        $req = new ServerRequest('GET', '/');
        $view = $this->resolver->execute($action, 'Read', $req);
        $this->assertSame('Specific', $view);
    }

    public function testFallsBackToGenericExecute()
    {
        $action = new class extends AgaviAction { public function execute(ServerRequestInterface $req){ return 'Generic'; } };
        $req = new ServerRequest('POST', '/');
        $view = $this->resolver->execute($action, 'Write', $req);
        $this->assertSame('Generic', $view);
    }

    public function testFallsBackToDefaultViewName()
    {
        $action = new class extends AgaviAction { public function getDefaultViewName(){ return 'Fallback'; } };
        $req = new ServerRequest('PATCH', '/');
        $view = $this->resolver->execute($action, 'Patch', $req);
        $this->assertSame('Fallback', $view);
    }

    public function testThrowsWhenNoMethodsOrDefault()
    {
        $action = new class extends AgaviAction { public function getDefaultViewName(){ return ''; } };
        $req = new ServerRequest('DELETE', '/');
        $this->expectException(Agavi\Exception\AgaviException::class);
        $this->resolver->execute($action, 'Delete', $req);
    }
}
