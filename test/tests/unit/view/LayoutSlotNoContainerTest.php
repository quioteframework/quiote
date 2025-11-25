<?php

use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Execution\SlotRenderable;
use Agavi\Execution\SlotStack;
use Agavi\Request\AgaviWebRequest;
use Nyholm\Psr7\ServerRequest;

/**
 * Ensures layout slot population uses SlotRenderable (value object) when no-container flags enabled.
 */
class LayoutSlotNoContainerTest extends AgaviUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('AGAVI_SLOT_SIMPLE_NO_CONTAINER=1');
        putenv('AGAVI_SLOT_NO_CONTAINER_ALL=1');
    }

    protected function tearDown(): void
    {
        putenv('AGAVI_SLOT_SIMPLE_NO_CONTAINER');
        putenv('AGAVI_SLOT_NO_CONTAINER_ALL');
        parent::tearDown();
    }

    public function testLayoutSlotsAreSlotRenderable()
    {
    $controller = $this->getContext()->getController();
    // Ensure Cache module/action available
    $controller->initializeModule('Cache');
    $controller->createActionInstance('Cache','CacheComplex');
    \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(false,false,false);
    $view = new class extends \Agavi\View\AgaviView { public function execute(AgaviWebRequest $rd) { return null; } };
    $descriptor = \Agavi\Execution\ActionDescriptor::fromController($controller,'Cache','CacheComplex','GET', strtolower($controller->getOutputType()->getName()));
    $vic = new \Agavi\Execution\ImmutableViewInitContext($this->getContext(),'Cache','CacheComplexSuccess', strtolower($controller->getOutputType()->getName()), 'Cache','CacheComplex', [], $controller->getGlobalResponse());
    $view->initialize($vic);
    // Ensure context has a request with SlotStack
    $req = $this->getContext()->getRequest();
    if (!$req) {
        $req = new \Agavi\Request\AgaviWebRequest('GET', 'http://localhost/layout-test');
        $req->initialize($this->getContext());
    }
    $req = $req->withAttribute(SlotStack::class, new SlotStack());
    $this->getContext()->setRequest($req);
    // Inject synthetic layout with a slot via reflection on output type
    $ot = $controller->getOutputType();
    $r = new ReflectionObject($ot);
    $prop = $r->getProperty('layouts');
    $prop->setAccessible(true);
    $layouts = $prop->getValue($ot);
    $layouts['testlayout'] = [
        'layers' => [
            'decorator' => [
                'class' => 'Agavi\\View\\AgaviFileTemplateLayer',
                'renderer' => null,
                'parameters' => [],
                'slots' => [
                    'testslot' => [
                        'module' => 'Cache',
                        'action' => 'CacheComplex',
                        'parameters' => [],
                        'output_type' => null,
                        'request_method' => null,
                    ],
                ],
            ],
        ],
        'parameters' => [],
    ];
    $prop->setValue($ot, $layouts);
    $view->loadLayout('testlayout');
    $layers = $view->getLayers();
    $this->assertNotEmpty($layers, 'Expected at least one layer loaded');
    $foundSlots = 0;
    foreach($layers as $layer) {
        foreach($layer->getSlots() as $name => $slotObj) {
            $foundSlots++;
            $this->assertInstanceOf(SlotRenderable::class, $slotObj, 'Slot should be SlotRenderable (value object path)');
            $this->assertTrue(is_object($slotObj), 'Slot object should be instantiated');
            $this->assertNotSame('', $slotObj->getContent(), 'Slot content should not be empty');
        }
    }
    $this->assertGreaterThan(0, $foundSlots, 'Expected at least one slot in loaded layout');
    }
}
