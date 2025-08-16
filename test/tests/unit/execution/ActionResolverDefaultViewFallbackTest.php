<?php
use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Execution\ActionResolver;
use Agavi\Request\AgaviRequestDataHolder;
use Agavi\Action\AgaviAction;

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
        $action = new class extends AgaviAction { public function executeRead(AgaviRequestDataHolder $rd){ return 'Specific'; } };
        $rd = new AgaviRequestDataHolder();
        $view = $this->resolver->execute($action, 'Read', $rd);
        $this->assertSame('Specific', $view);
    }

    public function testFallsBackToGenericExecute()
    {
        $action = new class extends AgaviAction { public function execute(AgaviRequestDataHolder $rd){ return 'Generic'; } };
        $rd = new AgaviRequestDataHolder();
        $view = $this->resolver->execute($action, 'Write', $rd);
        $this->assertSame('Generic', $view);
    }

    public function testFallsBackToDefaultViewName()
    {
        $action = new class extends AgaviAction { public function getDefaultViewName(){ return 'Fallback'; } };
        $rd = new AgaviRequestDataHolder();
        $view = $this->resolver->execute($action, 'Patch', $rd);
        $this->assertSame('Fallback', $view);
    }

    public function testThrowsWhenNoMethodsOrDefault()
    {
        $action = new class extends AgaviAction { public function getDefaultViewName(){ return ''; } };
        $rd = new AgaviRequestDataHolder();
        $this->expectException(Agavi\Exception\AgaviException::class);
        $this->resolver->execute($action, 'Delete', $rd);
    }
}
