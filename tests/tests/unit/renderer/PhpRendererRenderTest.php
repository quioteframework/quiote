<?php

declare(strict_types=1);

use Quiote\Renderer\PhpRenderer;
use Quiote\Testing\UnitTestCase;
use Quiote\View\FileTemplateLayer;

/**
 * PhpRenderer::render() around the template include itself: the legacy
 * template variables it has to supply, the empty-layer short circuit, and the
 * output-buffer unwinding that keeps a throwing template from leaving a
 * half-open buffer behind for the rest of the request.
 */
final class PhpRendererRenderTest extends UnitTestCase
{
    private string $dir;

    /** @var list<string> */
    private array $written = [];

    #[\Override]
    public function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/quiote-php-renderer-render-test';
        if (!is_dir($this->dir)) {
            mkdir($this->dir);
        }
    }

    #[\Override]
    public function tearDown(): void
    {
        foreach ($this->written as $file) {
            @unlink($file);
        }
    }

    /** Writes a template and returns the path without its .php extension, as the layer wants it. */
    private function writeTemplate(string $name, string $contents): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path . '.php', $contents);
        $this->written[] = $path . '.php';

        return $path;
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $layerParameters
     */
    private function render(?string $template, array $attributes = [], array $layerParameters = []): string
    {
        $renderer = new PhpRenderer();
        $renderer->initialize($this->getContext());

        $layer = new FileTemplateLayer($template === null ? $layerParameters : ['template' => $template, ...$layerParameters]);
        $layer->initialize($this->getContext());
        $layer->setRenderer($renderer);

        $slots = [];
        $moreAssigns = [];

        return $renderer->render($layer, $attributes, $slots, $moreAssigns);
    }

    public function testTheTemplateIsIncludedAndItsOutputReturned(): void
    {
        $template = $this->writeTemplate('plain', '<?php echo "hello from the template";');

        $this->assertSame('hello from the template', $this->render($template));
    }

    public function testTemplateOutputIsCapturedRatherThanEmitted(): void
    {
        $template = $this->writeTemplate('captured', '<?php echo "captured";');

        ob_start();
        $result = $this->render($template);
        $emitted = ob_get_clean();

        $this->assertSame('captured', $result);
        $this->assertSame('', $emitted, 'the template must not write past the renderer');
    }

    public function testAttributesReachTheTemplateUnderTheContainerVariable(): void
    {
        $template = $this->writeTemplate('attrs', '<?php echo $template["title"];');

        $this->assertSame('Quiote', $this->render($template, ['title' => 'Quiote']));
    }

    /**
     * Templates written against the older renderer read the module and action
     * names off the container variable, which the layer knows but the
     * attributes do not carry, so the renderer supplies them.
     */
    public function testTheModuleAndTemplateNamesAreSuppliedAsLegacyAttributes(): void
    {
        $template = $this->writeTemplate('legacy', '<?php echo $template["moduleName"] . "/" . $template["actionName"];');

        $result = $this->render($template, [], ['module' => 'Blog']);

        $this->assertStringStartsWith('Blog/', $result);
    }

    /** An attribute the caller set wins over the layer-derived fallback. */
    public function testAnExplicitModuleNameAttributeIsNotOverwritten(): void
    {
        $template = $this->writeTemplate('legacy-explicit', '<?php echo $template["moduleName"];');

        $result = $this->render($template, ['moduleName' => 'Explicit'], ['module' => 'Blog']);

        $this->assertSame('Explicit', $result);
    }

    /** `$inner` is the combined child content legacy layouts echo. */
    public function testTheInnerAttributeIsExposedAsItsOwnVariable(): void
    {
        $template = $this->writeTemplate('inner', '<?php echo $inner;');

        $this->assertSame('child content', $this->render($template, ['inner' => 'child content']));
    }

    public function testInnerIsNullWhenNoSuchAttributeWasSet(): void
    {
        $template = $this->writeTemplate('inner-absent', '<?php var_export($inner);');

        $this->assertSame('NULL', $this->render($template));
    }

    /**
     * A layer with no template is "nothing to render" rather than an error:
     * requiring an empty path would be a fatal, and a slot that resolved to no
     * layer is a normal outcome.
     */
    public function testALayerWithNoTemplateRendersAsEmpty(): void
    {
        $this->assertSame('', $this->render(null));
    }

    public function testALayerWithNoTemplateLeavesNoOutputBufferOpen(): void
    {
        $before = ob_get_level();

        $this->render(null);

        $this->assertSame($before, ob_get_level());
    }

    /**
     * A template that throws must not leave its output buffer open: the
     * exception travels up to ErrorHandlingMiddleware, which renders the error
     * response into whatever buffer state it inherits.
     */
    public function testAThrowingTemplateUnwindsItsOwnBufferBeforeBubbling(): void
    {
        $template = $this->writeTemplate('boom', '<?php echo "partial"; throw new RuntimeException("template exploded");');
        $before = ob_get_level();

        try {
            $this->render($template);
            $this->fail('the template exception must reach the caller');
        } catch (RuntimeException $e) {
            $this->assertSame('template exploded', $e->getMessage());
        }

        $this->assertSame($before, ob_get_level(), 'a leaked buffer would swallow the rest of the response');
    }

    /**
     * Only the buffers the renderer opened are unwound. A template that opens
     * one of its own and throws before closing it is the renderer's to clean
     * up; anything the caller had open before is not.
     */
    public function testAnOuterBufferOpenedByTheCallerSurvivesAThrowingTemplate(): void
    {
        $template = $this->writeTemplate('boom-nested', '<?php ob_start(); echo "swallowed"; throw new RuntimeException("nested");');

        ob_start();
        $outerLevel = ob_get_level();

        try {
            $this->render($template);
            $this->fail('the template exception must reach the caller');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame($outerLevel, ob_get_level(), "the caller's own buffer must still be open");
        ob_end_clean();
    }

    // --- reuse -------------------------------------------------------------

    /**
     * PhpRenderer is reusable: reset() drops only the per-render state, so the
     * configured variable names survive for the next rendering.
     */
    public function testResetClearsPerRenderStateButKeepsTheConfiguration(): void
    {
        $renderer = new PhpRenderer();
        $renderer->initialize($this->getContext(), ['var_name' => 'vars']);

        $renderer->reset();

        $this->assertSame('vars', (new ReflectionProperty(\Quiote\Renderer\Renderer::class, 'varName'))->getValue($renderer));
        foreach (['layer', 'attributes', 'slots', 'moreAssigns'] as $property) {
            $this->assertNull(
                (new ReflectionProperty(PhpRenderer::class, $property))->getValue($renderer),
                $property . ' is per-render state and must not survive',
            );
        }
    }

    public function testARendererRendersCorrectlyAfterBeingReset(): void
    {
        $first = $this->writeTemplate('reuse-one', '<?php echo "first";');
        $second = $this->writeTemplate('reuse-two', '<?php echo "second";');

        $renderer = new PhpRenderer();
        $renderer->initialize($this->getContext());

        $this->assertSame('first', $this->renderWith($renderer, $first));
        $renderer->reset();
        $this->assertSame('second', $this->renderWith($renderer, $second));
    }

    private function renderWith(PhpRenderer $renderer, string $template): string
    {
        $layer = new FileTemplateLayer(['template' => $template]);
        $layer->initialize($this->getContext());
        $layer->setRenderer($renderer);

        $attributes = [];
        $slots = [];
        $moreAssigns = [];

        return $renderer->render($layer, $attributes, $slots, $moreAssigns);
    }
}
