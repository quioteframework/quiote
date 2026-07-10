<?php

use Quiote\Renderer\PhpRenderer;
use Quiote\Testing\UnitTestCase;
use Quiote\View\FileTemplateLayer;

final class PhpRendererStarterTemplateTest extends UnitTestCase
{
    private string $templateBase;

    #[\Override]
    public function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/quiote-php-renderer-starter-test';
        if (!is_dir($dir)) {
            mkdir($dir);
        }
        $this->templateBase = $dir . '/greeting';
    }

    #[\Override]
    public function tearDown(): void
    {
        @unlink($this->templateBase . '.php');
        @unlink($this->templateBase . '-extract.php');
    }

    public function testStarterTemplateRendersTitleFromAttributes(): void
    {
        $renderer = new PhpRenderer();
        $renderer->initialize($this->getContext());

        $starter = $renderer->getStarterTemplate();
        $this->assertNotNull($starter);
        file_put_contents($this->templateBase . '.php', $starter);

        $layer = new FileTemplateLayer(['template' => $this->templateBase]);
        $layer->initialize($this->getContext());
        $layer->setRenderer($renderer);

        $attributes = ['title' => 'Quiote'];
        $output = $layer->execute($renderer, $attributes);

        $this->assertStringContainsString('Quiote', $output);
    }

    public function testStarterTemplateEscapesTitle(): void
    {
        $renderer = new PhpRenderer();
        $renderer->initialize($this->getContext());

        file_put_contents($this->templateBase . '.php', $renderer->getStarterTemplate());

        $layer = new FileTemplateLayer(['template' => $this->templateBase]);
        $layer->initialize($this->getContext());
        $layer->setRenderer($renderer);

        $attributes = ['title' => '<b>x</b>'];
        $output = $layer->execute($renderer, $attributes);

        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $output);
        $this->assertStringNotContainsString('<b>x</b>', $output);
    }

    public function testStarterTemplateHonorsExtractVars(): void
    {
        $renderer = new PhpRenderer();
        $renderer->initialize($this->getContext(), ['extract_vars' => true]);

        $this->templateBase .= '-extract';
        file_put_contents($this->templateBase . '.php', $renderer->getStarterTemplate());

        $layer = new FileTemplateLayer(['template' => $this->templateBase]);
        $layer->initialize($this->getContext());
        $layer->setRenderer($renderer);

        $attributes = ['title' => 'Extracted'];
        $output = $layer->execute($renderer, $attributes);

        $this->assertStringContainsString('Extracted', $output);
    }
}

?>
