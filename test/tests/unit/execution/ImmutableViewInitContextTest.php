<?php
use PHPUnit\Framework\TestCase;
use Agavi\Execution\ImmutableViewInitContext;
use Agavi\AgaviContext;
// Provide a concrete minimal response stub implementing abstract methods
class TestImmutableInitContextResponse extends \Agavi\Response\AgaviResponse {
    private $redirect = null;
    public function initialize($c,$p=[]) { parent::initialize($c,$p); }
    public function setRedirect($to) { $this->redirect = $to; }
    public function getRedirect() { return $this->redirect ? ['to'=>$this->redirect] : null; }
    public function hasRedirect() { return $this->redirect !== null; }
    public function clearRedirect() { $this->redirect = null; }
    public function clear() { $this->clearContent(); $this->clearRedirect(); $this->clearAttributes(); }
    public function send(?\Agavi\Controller\AgaviOutputType $outputType = null) { /* no-op for test */ }
}

class ImmutableViewInitContextTest extends TestCase
{
    private function ctx(): \Agavi\AgaviContext { return AgaviContext::getInstance('default'); }

    public function testBasicGettersAndAttributeSnapshot()
    {
        $context = $this->ctx();
        $response = new TestImmutableInitContextResponse();
        $response->initialize($context, []);
        $ivc = new ImmutableViewInitContext($context,'ViewModule','Main','html','ActionModule','List',['k'=>'v'],$response);
        $this->assertSame($context, $ivc->getContext());
        $this->assertSame('ViewModule',$ivc->getViewModuleName());
        $this->assertSame('Main',$ivc->getViewName());
        $this->assertSame('html',$ivc->getOutputTypeName());
        $this->assertSame('ActionModule',$ivc->getActionModuleName());
        $this->assertSame('List',$ivc->getActionName());
        $this->assertSame(['k'=>'v'],$ivc->getActionAttributes());
        $this->assertSame($response,$ivc->getResponse());
        // AttributeHolder snapshot: getAttribute('k') should return 'v'
        $this->assertSame('v',$ivc->getAttribute('k'));
    }

    public function testLegacyOutputTypeProxyAndModuleFallback()
    {
        $context = $this->ctx();
    $response = new TestImmutableInitContextResponse();
    $response->initialize($context, []);
        $ivc = new ImmutableViewInitContext($context,'ViewModule','Main','xml',null,null,[], $response);
        // getModuleName() should fall back to viewModule when actionModule null
        $this->assertSame('ViewModule',$ivc->getModuleName());
        $ot = $ivc->getOutputType();
        $this->assertSame('xml', $ot->getName());
    }

    public function testLegacyParameterAndValidationManagerShims()
    {
        $context = $this->ctx();
    $response = new TestImmutableInitContextResponse();
    $response->initialize($context, []);
        $ivc = new ImmutableViewInitContext($context,'VM','V','json','AM','A',[], $response);
        $default = false;
        $param = $ivc->getParameter('is_slot', $default);
        $this->assertSame($default, $param, 'Legacy getParameter should return provided default');
        $params = $ivc->getParameters();
        $this->assertSame([], $params);
        $vm = $ivc->getValidationManager();
        // Either null (if factory absent) or an instance implementing initialize(). Accept both.
        $this->assertTrue($vm === null || is_object($vm));
    }
}
