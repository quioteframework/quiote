<?php
use PHPUnit\Framework\TestCase;
use Quiote\Execution\LightweightActionInitContext;
use Nyholm\Psr7\ServerRequest;
// Concrete response stub implementing abstract methods
class TestLightweightResponse extends \Quiote\Response\WebResponse {
    /** @var array{location: mixed, code: int|string}|null */
    protected $redirect = null;
    #[\Override]
    public function initialize($c,$p=[]): void { parent::initialize($c,$p); }
    #[\Override]
    public function setRedirect($location, $code = 302): void { $this->redirect = ['location' => $location, 'code' => $code]; }
    #[\Override]
    public function getRedirect(): ?array { return $this->redirect; }
    #[\Override]
    public function hasRedirect(): bool { return $this->redirect !== null; }
    #[\Override]
    public function clearRedirect(): void { $this->redirect = null; }
    #[\Override]
    public function clear(): void { $this->clearContent(); $this->clearRedirect(); $this->clearAttributes(); }
    #[\Override]
    public function send(?\Quiote\Controller\OutputType $outputType = null): void { /* no-op */ }
}
use Quiote\Context;

class LightweightActionInitContextTest extends TestCase
{
    private function ctx(): \Quiote\Context { return Context::getInstance('default'); }

    public function testCoreGetters(): void
    {
        $context = $this->ctx();
        $request = new ServerRequest('GET','/items');
    $response = new TestLightweightResponse();
    $response->initialize($context, []);
        $aic = new LightweightActionInitContext($context,'Items','List','GET','html',$request,$response);
        $this->assertSame($context,$aic->getContext());
        $this->assertSame('Items',$aic->getModuleName());
        $this->assertSame('List',$aic->getActionName());
        $this->assertSame('GET',$aic->getRequestMethod());
        $this->assertSame('html',$aic->getOutputTypeName());
        $this->assertSame($request,$aic->getRequestData());
        $this->assertSame($response,$aic->getResponse());
        $this->assertNull($aic->getViewModuleName());
        $this->assertNull($aic->getViewName());
    }

    public function testSetterAndGetterForViewNames(): void
    {
        $context = $this->ctx();
    $response = new TestLightweightResponse();
    $response->initialize($context, []);
        $aic = new LightweightActionInitContext($context,'Mod','Act','POST','json',null,$response);
        $this->assertNull($aic->getViewModuleName());
        $this->assertNull($aic->getViewName());
        $aic->setViewModuleName('ModView');
        $aic->setViewName('Success');
        $this->assertSame('ModView',$aic->getViewModuleName());
        $this->assertSame('Success',$aic->getViewName());
    }

    public function testValidationManagerShim(): void
    {
        $context = $this->ctx();
    $response = new TestLightweightResponse();
    $response->initialize($context, []);
        $aic = new LightweightActionInitContext($context,'M','A','PUT','xml',null,$response);
        $vm = $aic->getValidationManager();
        if ($vm !== null) {
            $this->assertTrue(method_exists($vm, 'initialize'), 'Validation manager must implement initialize()');
        }
    }
}
