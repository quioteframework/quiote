<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Execution\SlotRenderable;
use Quiote\Execution\SlotStack;
use Quiote\Request\WebRequest;
use Nyholm\Psr7\ServerRequest;

/**
 * Ensures layout slot population uses SlotRenderable (value object) when no-container flags enabled.
 */
class LayoutSlotNoContainerTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('QUIOTE_SLOT_SIMPLE_NO_CONTAINER=1');
        putenv('QUIOTE_SLOT_NO_CONTAINER_ALL=1');
    }

    protected function tearDown(): void
    {
        putenv('QUIOTE_SLOT_SIMPLE_NO_CONTAINER');
        putenv('QUIOTE_SLOT_NO_CONTAINER_ALL');
        parent::tearDown();
    }

    public function testLayoutSlotsAreSlotRenderable(): void
    {
    $controller = $this->getContext()->getController();
    // Ensure Cache module/action available
    $controller->initializeModule('Cache');
    $controller->createActionInstance('Cache','CacheComplex');
    \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(false,false,false);
    $view = new class extends \Quiote\View\View { public function execute(WebRequest $rd) { return null; } };
    $descriptor = \Quiote\Execution\ActionDescriptor::fromController($controller,'Cache','CacheComplex','GET', strtolower($controller->getOutputType()->getName()));
    $vic = new \Quiote\Execution\ImmutableViewInitContext($this->getContext(),'Cache','CacheComplexSuccess', strtolower($controller->getOutputType()->getName()), 'Cache','CacheComplex', [], $controller->getGlobalResponse());
    $view->initialize($vic);
    // getRequest() always returns a WebRequest instance (lazily recreated if needed),
    // so no null fallback is required here.
    $req = $this->getContext()->getRequest();
    $req = $req->withAttribute(SlotStack::class, new SlotStack());
    $this->getContext()->setRequest($req);
    // Inject synthetic layout with a slot via reflection on output type
    $ot = $controller->getOutputType();
    $r = new ReflectionObject($ot);
    $prop = $r->getProperty('layouts');
    // $prop->setAccessible(true); // Deprecated, not needed in PHP 8.1+
    $layouts = $prop->getValue($ot);
    $layouts['testlayout'] = [
        'layers' => [
            'decorator' => [
                'class' => \Quiote\View\FileTemplateLayer::class,
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
        foreach($layer->getSlots() as $slotObj) {
            $foundSlots++;
            $this->assertInstanceOf(SlotRenderable::class, $slotObj, 'Slot should be SlotRenderable (value object path)');
            $this->assertNotSame('', $slotObj->getContent(), 'Slot content should not be empty');
        }
    }
    $this->assertGreaterThan(0, $foundSlots, 'Expected at least one slot in loaded layout');
    }
}
