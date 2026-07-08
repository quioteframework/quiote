<?php
use PHPUnit\Framework\TestCase;
use Quiote\Execution\ImmutableViewInitContext;
use Quiote\Context;
// Provide a concrete minimal response stub implementing abstract methods
class TestImmutableInitContextResponse extends \Quiote\Response\WebResponse {
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
    public function send(?\Quiote\Controller\OutputType $outputType = null): void { /* no-op for test */ }
}

class ImmutableViewInitContextTest extends TestCase
{
    private function ctx(): \Quiote\Context { return Context::getInstance('default'); }

    public function testBasicGettersAndAttributeSnapshot(): void
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

    public function testLegacyOutputTypeProxyAndModuleFallback(): void
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

    public function testLegacyParameterAndValidationManagerShims(): void
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
        // Either null (if factory absent) or an instance implementing initialize(). Accept both,
        // but verify the contract actually holds for the non-null case.
        if ($vm !== null) {
            $this->assertTrue(method_exists($vm, 'initialize'), 'Validation manager must implement initialize()');
        }
    }
}
