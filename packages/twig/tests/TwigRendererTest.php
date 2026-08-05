<?php

use Quiote\Exception\RenderException;
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
        foreach (['', '-extract', '-assigns', '-starter', '-starter-default', '-starter-extract'] as $suffix) {
            @unlink($this->templateBase . $suffix . '.twig');
        }
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

    public function testStarterTemplateRendersTitleFromAttributes(): void
    {
        $renderer = new TwigRenderer();
        $renderer->initialize($this->getContext());

        $this->templateBase .= '-starter';
        file_put_contents($this->templateBase . '.twig', $renderer->getStarterTemplate());

        $layer = new FileTemplateLayer(['template' => $this->templateBase]);
        $layer->initialize($this->getContext());
        $layer->setRenderer($renderer);

        $attributes = ['title' => 'Quiote'];
        $output = $layer->execute($renderer, $attributes);

        $this->assertStringContainsString('Quiote', $output);
    }

    public function testStarterTemplateFallsBackToDefaultWhenTitleMissing(): void
    {
        $renderer = new TwigRenderer();
        $renderer->initialize($this->getContext());

        $this->templateBase .= '-starter-default';
        file_put_contents($this->templateBase . '.twig', $renderer->getStarterTemplate());

        $layer = new FileTemplateLayer(['template' => $this->templateBase]);
        $layer->initialize($this->getContext());
        $layer->setRenderer($renderer);

        $attributes = [];
        $output = $layer->execute($renderer, $attributes);

        $this->assertStringContainsString('Untitled', $output);
    }

    public function testStarterTemplateHonorsExtractVars(): void
    {
        $renderer = new TwigRenderer();
        $renderer->initialize($this->getContext(), ['extract_vars' => true]);

        $this->templateBase .= '-starter-extract';
        file_put_contents($this->templateBase . '.twig', $renderer->getStarterTemplate());

        $layer = new FileTemplateLayer(['template' => $this->templateBase]);
        $layer->initialize($this->getContext());
        $layer->setRenderer($renderer);

        $attributes = ['title' => 'Extracted'];
        $output = $layer->execute($renderer, $attributes);

        $this->assertStringContainsString('Extracted', $output);
    }

    public function testRenderThrowsWhenNoTemplateIsSet(): void
    {
        $renderer = new TwigRenderer();
        $renderer->initialize($this->getContext());

        $layer = new FileTemplateLayer();
        $layer->initialize($this->getContext());
        $layer->setRenderer($renderer);

        $this->expectException(RenderException::class);
        $this->expectExceptionMessage('No template is set on the template layer.');

        $attributes = [];
        $layer->execute($renderer, $attributes);
    }

    /**
     * An `assigns` entry names something to expose alongside the attributes. The base renderer
     * resolves each one to a closure, so this renderer has to call it rather than treat the value as
     * a Context method name.
     */
    public function testAnAssignedContainerServiceReachesTheTemplate(): void
    {
        $renderer = new TwigRenderer();
        $renderer->initialize($this->getContext(), ['assigns' => ['user' => 'currentUser']]);

        $this->templateBase .= '-assigns';
        file_put_contents($this->templateBase . '.twig', 'Hello, {{ currentUser.attribute("name") }}!');

        $layer = new FileTemplateLayer(['template' => $this->templateBase]);
        $layer->initialize($this->getContext());
        $layer->setRenderer($renderer);

        $user = $this->getContext()->getContainer()->get(\Quiote\User\User::class);
        $user->setAttribute('name', 'Quiote');

        $attributes = [];
        $this->assertSame('Hello, Quiote!', $layer->execute($renderer, $attributes));
    }

}
