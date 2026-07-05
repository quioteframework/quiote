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
        if (file_exists($this->templateBase . '.tal'))
            @unlink($this->templateBase . '.tal');
        if (file_exists($this->templateBase . '-extract.tal'))
            @unlink($this->templateBase . '-extract.tal');
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
}
