<?php

use Quiote\Testing\UnitTestCase;
use Quiote\View\TemplateLayer;
use Quiote\View\View;
use Quiote\Exception\ViewException;
use Quiote\Request\WebRequest;

// Minimal template layer avoiding stream/render resolution logic.
class OrderingTestLayer extends TemplateLayer {
    public function getResourceStreamIdentifier() { return null; }
}

class OrderingTestView extends View {
    public function execute(WebRequest $rd): void {}
}

/**
 * Covers View::appendLayer()/prependLayer() ordering (happy path) and the
 * "otherLayer not registered" failure path (both already covered a not-found
 * $otherLayer at the top of each method, but this locks in that contract now
 * that the destination-index resolution was hardened against array_search()
 * returning false).
 */
class ViewLayerOrderingTest extends UnitTestCase
{
    private function layer(string $name): OrderingTestLayer
    {
        return new OrderingTestLayer(['name' => $name]);
    }

    public function testAppendLayerWithoutOtherAddsToEnd(): void
    {
        $view = new OrderingTestView();
        $a = $this->layer('a');
        $b = $this->layer('b');
        $view->appendLayer($a);
        $view->appendLayer($b);
        $this->assertSame([$a, $b], $view->getLayers());
    }

    public function testAppendLayerAfterOtherInsertsAtCorrectPosition(): void
    {
        $view = new OrderingTestView();
        $a = $this->layer('a');
        $b = $this->layer('b');
        $c = $this->layer('c');
        $view->appendLayer($a);
        $view->appendLayer($c);
        $view->appendLayer($b, $a); // insert b right after a -> a, b, c
        $this->assertSame([$a, $b, $c], $view->getLayers());
    }

    public function testPrependLayerWithoutOtherAddsToStart(): void
    {
        $view = new OrderingTestView();
        $a = $this->layer('a');
        $b = $this->layer('b');
        $view->appendLayer($a);
        $view->prependLayer($b);
        $this->assertSame([$b, $a], $view->getLayers());
    }

    public function testPrependLayerBeforeOtherInsertsAtCorrectPosition(): void
    {
        $view = new OrderingTestView();
        $a = $this->layer('a');
        $b = $this->layer('b');
        $c = $this->layer('c');
        $view->appendLayer($a);
        $view->appendLayer($c);
        $view->prependLayer($b, $c); // insert b right before c -> a, b, c
        $this->assertSame([$a, $b, $c], $view->getLayers());
    }

    public function testAppendLayerWithUnregisteredOtherLayerThrows(): void
    {
        $view = new OrderingTestView();
        $a = $this->layer('a');
        $notInList = $this->layer('missing');
        $this->expectException(ViewException::class);
        $view->appendLayer($a, $notInList);
    }

    public function testPrependLayerWithUnregisteredOtherLayerThrows(): void
    {
        $view = new OrderingTestView();
        $a = $this->layer('a');
        $notInList = $this->layer('missing');
        $this->expectException(ViewException::class);
        $view->prependLayer($a, $notInList);
    }
}
