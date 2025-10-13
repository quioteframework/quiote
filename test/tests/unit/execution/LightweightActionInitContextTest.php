<?php
use PHPUnit\Framework\TestCase;
use Agavi\Execution\LightweightActionInitContext;
use Nyholm\Psr7\ServerRequest;
// Concrete response stub implementing abstract methods
class TestLightweightResponse extends \Agavi\Response\AgaviResponse {
    private $redirect = null;
    public function initialize($c,$p=[]) { parent::initialize($c,$p); }
    public function setRedirect($to) { $this->redirect = $to; }
    public function getRedirect() { return $this->redirect ? ['to'=>$this->redirect] : null; }
    public function hasRedirect() { return $this->redirect !== null; }
    public function clearRedirect() { $this->redirect = null; }
    public function clear() { $this->clearContent(); $this->clearRedirect(); $this->clearAttributes(); }
    public function send(?\Agavi\Controller\AgaviOutputType $outputType = null) { /* no-op */ }
}
use Agavi\AgaviContext;

class LightweightActionInitContextTest extends TestCase
{
    private function ctx(): \Agavi\AgaviContext { return AgaviContext::getInstance('default'); }

    public function testCoreGetters()
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

    public function testSetterAndGetterForViewNames()
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

    public function testValidationManagerShim()
    {
        $context = $this->ctx();
    $response = new TestLightweightResponse();
    $response->initialize($context, []);
        $aic = new LightweightActionInitContext($context,'M','A','PUT','xml',null,$response);
        $vm = $aic->getValidationManager();
        $this->assertTrue($vm === null || is_object($vm));
    }
}
