<?php
use Quiote\Testing\UnitTestCase;
use Quiote\View\TemplateLayer;
use Quiote\Renderer\Renderer;
use Quiote\Execution\SlotRenderable;

// Stub SlotRenderable that would fail if legacy execute() path were used (no execute method)
class StubSlotRenderable implements SlotRenderable {
    public function __construct(private readonly string $content) {}
    public function getContent(): string { return $this->content; }
}

// Minimal renderer stub
class StubRenderer extends Renderer {
    public function render(TemplateLayer $layer, array &$attributes = [], array &$slots = [], array &$moreAssigns = []) {
        // Return slots joined by '|'
        ksort($slots);
        return implode('|', $slots);
    }
}

// Minimal template layer to avoid stream logic
class TestTemplateLayer extends TemplateLayer {
    public function getResourceStreamIdentifier() { return null; }
}

class TemplateLayerSlotRenderableTest extends UnitTestCase
{
    public function testRenderableBypassesLegacyExecution()
    {
        $layer = new TestTemplateLayer();
        $layer->initialize($this->getContext(), []);
        $layer->setRenderer(new StubRenderer());
        $layer->setSlot('alpha', new StubSlotRenderable('A_CONTENT'));
        $result = $layer->execute();
        $this->assertSame('A_CONTENT', $result);
    }

    public function testMixedLegacyAndRenderableSlots()
    {
        // Ensure Cache module available for legacy container slot
        $this->getContext()->getController()->initializeModule('Cache');
        $layer = new TestTemplateLayer();
        $layer->initialize($this->getContext(), []);
        $layer->setRenderer(new StubRenderer());
        // Simulate legacy slot output via SlotRenderable stub to avoid container
        $legacyContent = new class implements SlotRenderable { public function getContent(): string { return 'LEGACY_SIM'; } };
        $layer->setSlot('legacy', $legacyContent);
        // New renderable slot
        $layer->setSlot('renderable', new StubSlotRenderable('R_CONTENT'));
        $result = $layer->execute();
        // Order after ksort: legacy, renderable
        $this->assertStringContainsString('R_CONTENT', $result);
        $this->assertMatchesRegularExpression('/LEGACY_SIM.*\|R_CONTENT/', $result);
    }
}
