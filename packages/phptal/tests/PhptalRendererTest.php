<?php

use Quiote\Renderer\Phptal\PhptalRenderer;
use Quiote\Testing\UnitTestCase;
use Quiote\View\FileTemplateLayer;

final class PhptalRendererTest extends UnitTestCase
{
    private string $templateBase;

    #[\Override]
    public function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/quiote-phptal-renderer-test';
        if (!is_dir($dir)) {
            mkdir($dir);
        }
        $this->templateBase = $dir . '/greeting';
        file_put_contents($this->templateBase . '.tal', '<p tal:content="template/name">placeholder</p>');
    }

    #[\Override]
    public function tearDown(): void
    {
        foreach (['', '-extract', '-starter', '-starter-default', '-starter-extract'] as $suffix) {
            @unlink($this->templateBase . $suffix . '.tal');
        }
    }

    public function testRendersTemplateWithAttributes(): void
    {
        $renderer = new PhptalRenderer();
        $renderer->initialize($this->getContext());

        $layer = new FileTemplateLayer(['template' => $this->templateBase]);
        $layer->initialize($this->getContext());
        $layer->setRenderer($renderer);

        $attributes = ['name' => 'Quiote'];
        $output = $layer->execute($renderer, $attributes);

        $this->assertStringContainsString('Quiote', $output);
    }

    public function testExtractVarsExposesAttributesDirectly(): void
    {
        $renderer = new PhptalRenderer();
        $renderer->initialize($this->getContext(), ['extract_vars' => true]);

        // Separate file from testRendersTemplateWithAttributes(): PHPTAL's on-disk
        // compiled-template cache keys on path + mtime, and overwriting the same
        // path within the same second (as two tests in one run can) risks reusing
        // a stale compiled class built against the other test's template markup.
        $this->templateBase .= '-extract';
        file_put_contents($this->templateBase . '.tal', '<p tal:content="name">placeholder</p>');

        $layer = new FileTemplateLayer(['template' => $this->templateBase]);
        $layer->initialize($this->getContext());
        $layer->setRenderer($renderer);

        $attributes = ['name' => 'Extracted'];
        $output = $layer->execute($renderer, $attributes);

        $this->assertStringContainsString('Extracted', $output);
    }

    public function testStarterTemplateRendersTitleFromAttributes(): void
    {
        $renderer = new PhptalRenderer();
        $renderer->initialize($this->getContext());

        $this->templateBase .= '-starter';
        file_put_contents($this->templateBase . '.tal', $renderer->getStarterTemplate());

        $layer = new FileTemplateLayer(['template' => $this->templateBase]);
        $layer->initialize($this->getContext());
        $layer->setRenderer($renderer);

        $attributes = ['title' => 'Quiote'];
        $output = $layer->execute($renderer, $attributes);

        $this->assertStringContainsString('Quiote', $output);
    }

    public function testStarterTemplateFallsBackToDefaultWhenTitleMissing(): void
    {
        $renderer = new PhptalRenderer();
        $renderer->initialize($this->getContext());

        $this->templateBase .= '-starter-default';
        file_put_contents($this->templateBase . '.tal', $renderer->getStarterTemplate());

        $layer = new FileTemplateLayer(['template' => $this->templateBase]);
        $layer->initialize($this->getContext());
        $layer->setRenderer($renderer);

        $attributes = [];
        $output = $layer->execute($renderer, $attributes);

        $this->assertStringContainsString('Untitled', $output);
    }

    public function testStarterTemplateHonorsExtractVars(): void
    {
        $renderer = new PhptalRenderer();
        $renderer->initialize($this->getContext(), ['extract_vars' => true]);

        $this->templateBase .= '-starter-extract';
        file_put_contents($this->templateBase . '.tal', $renderer->getStarterTemplate());

        $layer = new FileTemplateLayer(['template' => $this->templateBase]);
        $layer->initialize($this->getContext());
        $layer->setRenderer($renderer);

        $attributes = ['title' => 'Extracted'];
        $output = $layer->execute($renderer, $attributes);

        $this->assertStringContainsString('Extracted', $output);
    }
}
