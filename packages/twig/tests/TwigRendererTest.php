<?php

use Quiote\Renderer\Twig\TwigRenderer;
use Quiote\Testing\UnitTestCase;
use Quiote\View\FileTemplateLayer;

final class TwigRendererTest extends UnitTestCase
{
    private string $templateBase;

    #[\Override]
    public function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/quiote-twig-renderer-test';
        if (!is_dir($dir)) {
            mkdir($dir);
        }
        $this->templateBase = $dir . '/greeting';
        file_put_contents($this->templateBase . '.twig', 'Hello, {{ template.name }}!');
    }

    #[\Override]
    public function tearDown(): void
    {
        @unlink($this->templateBase . '.twig');
        @unlink($this->templateBase . '-extract.twig');
    }

    public function testRendersTemplateWithAttributes(): void
    {
        $renderer = new TwigRenderer();
        $renderer->initialize($this->getContext());

        $layer = new FileTemplateLayer(['template' => $this->templateBase]);
        $layer->initialize($this->getContext());
        $layer->setRenderer($renderer);

        $attributes = ['name' => 'Quiote'];
        $output = $layer->execute($renderer, $attributes);

        $this->assertSame('Hello, Quiote!', $output);
    }

    public function testExtractVarsExposesAttributesDirectly(): void
    {
        $renderer = new TwigRenderer();
        $renderer->initialize($this->getContext(), ['extract_vars' => true]);

        // Separate file from testRendersTemplateWithAttributes(): Twig's cache
        // freshness check is mtime-based, and overwriting the same path within the
        // same second (as two tests in one run can) risks Twig reusing the other
        // test's compiled template.
        $this->templateBase .= '-extract';
        file_put_contents($this->templateBase . '.twig', 'Hello, {{ name }}!');

        $layer = new FileTemplateLayer(['template' => $this->templateBase]);
        $layer->initialize($this->getContext());
        $layer->setRenderer($renderer);

        $attributes = ['name' => 'Extracted'];
        $output = $layer->execute($renderer, $attributes);

        $this->assertSame('Hello, Extracted!', $output);
    }
}
