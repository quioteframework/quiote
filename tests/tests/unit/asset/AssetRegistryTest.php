<?php

use PHPUnit\Framework\TestCase;
use Quiote\Asset\AssetRegistry;

class AssetRegistryTest extends TestCase
{
    public function testStartsEmpty(): void
    {
        $registry = new AssetRegistry();
        $this->assertSame([], $registry->css());
        $this->assertSame([], $registry->javascript());
    }

    public function testAddCssPreservesInsertionOrder(): void
    {
        $registry = new AssetRegistry();
        $registry->addCss('css/one.css');
        $registry->addCss('css/two.css');
        $registry->addCss('css/three.css');
        $this->assertSame(['css/one.css', 'css/two.css', 'css/three.css'], $registry->css());
    }

    public function testAddJavascriptPreservesInsertionOrder(): void
    {
        $registry = new AssetRegistry();
        $registry->addJavascript('js/d3.min.js');
        $registry->addJavascript('js/d3-barchart.js');
        $this->assertSame(['js/d3.min.js', 'js/d3-barchart.js'], $registry->javascript());
    }

    public function testAddCssDeduplicatesRepeatedAssets(): void
    {
        $registry = new AssetRegistry();
        $registry->addCss('css/shared.css');
        $registry->addCss('css/shared.css');
        $this->assertSame(['css/shared.css'], $registry->css());
    }

    public function testAddJavascriptDeduplicatesButPreservesFirstInsertionPosition(): void
    {
        $registry = new AssetRegistry();
        // Simulates two slot-nested views both needing d3.min.js: the shared
        // dependency should render once, at the position it was first needed.
        $registry->addJavascript('js/d3.min.js');
        $registry->addJavascript('js/chart-a.js');
        $registry->addJavascript('js/d3.min.js');
        $registry->addJavascript('js/chart-b.js');
        $this->assertSame(
            ['js/d3.min.js', 'js/chart-a.js', 'js/chart-b.js'],
            $registry->javascript()
        );
    }

    public function testCssAndJavascriptAreIndependent(): void
    {
        $registry = new AssetRegistry();
        $registry->addCss('css/one.css');
        $this->assertSame(['css/one.css'], $registry->css());
        $this->assertSame([], $registry->javascript());
    }

    public function testResetClearsBothCssAndJavascript(): void
    {
        $registry = new AssetRegistry();
        $registry->addCss('css/one.css');
        $registry->addJavascript('js/one.js');
        $registry->reset();
        $this->assertSame([], $registry->css());
        $this->assertSame([], $registry->javascript());
    }

    public function testUsableAgainAfterReset(): void
    {
        $registry = new AssetRegistry();
        $registry->addCss('css/before-reset.css');
        $registry->reset();
        $registry->addCss('css/after-reset.css');
        $this->assertSame(['css/after-reset.css'], $registry->css());
    }
}
