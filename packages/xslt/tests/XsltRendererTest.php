<?php

use Quiote\Exception\RenderException;
use Quiote\Renderer\Xslt\XsltRenderer;
use Quiote\Testing\UnitTestCase;
use Quiote\View\FileTemplateLayer;

final class XsltRendererTest extends UnitTestCase
{
    private string $templateBase;

    #[\Override]
    public function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/quiote-xslt-renderer-test';
        if (!is_dir($dir)) {
            mkdir($dir);
        }
        $this->templateBase = $dir . '/greeting';
        file_put_contents($this->templateBase . '.xsl', <<<'XSL'
<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform">
    <xsl:output method="text" />
    <xsl:param name="name" />
    <xsl:template match="/">Hello, <xsl:value-of select="$name" />!</xsl:template>
</xsl:stylesheet>
XSL);
    }

    #[\Override]
    public function tearDown(): void
    {
        @unlink($this->templateBase . '.xsl');
        @unlink($this->templateBase . '-starter.xsl');
    }

    public function testTransformsInnerDocumentWithParameters(): void
    {
        $renderer = new XsltRenderer();
        $renderer->initialize($this->getContext());

        $layer = new FileTemplateLayer(['template' => $this->templateBase]);
        $layer->initialize($this->getContext());
        $layer->setRenderer($renderer);

        $attributes = ['name' => 'Quiote'];
        $moreAssigns = ['inner' => '<root/>'];
        $output = $layer->execute($renderer, $attributes, $moreAssigns);

        $this->assertStringContainsString('Hello, Quiote!', $output);
    }

    public function testEnvelopeWrapsInnerAndSlots(): void
    {
        file_put_contents($this->templateBase . '.xsl', <<<'XSL'
<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0"
    xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
    xmlns:e="http://quiote.org/quiote/renderer/xslt/envelope/1.0">
    <xsl:output method="text" />
    <xsl:template match="/">[<xsl:value-of select="//e:slot[@name='sidebar']" />]</xsl:template>
</xsl:stylesheet>
XSL);

        $renderer = new XsltRenderer();
        $renderer->initialize($this->getContext());

        $layer = new FileTemplateLayer(['template' => $this->templateBase]);
        $layer->initialize($this->getContext());
        $layer->setRenderer($renderer);

        $attributes = [];
        $moreAssigns = ['inner' => '<root/>'];
        $slots = ['sidebar' => '<box>widget</box>'];
        $output = $renderer->render($layer, $attributes, $slots, $moreAssigns);

        $this->assertStringContainsString('[widget]', $output);
    }

    public function testStarterTemplateRendersTitleFromAttributes(): void
    {
        $this->templateBase .= '-starter';
        file_put_contents($this->templateBase . '.xsl', (new XsltRenderer())->getStarterTemplate());

        $renderer = new XsltRenderer();
        $renderer->initialize($this->getContext());

        $layer = new FileTemplateLayer(['template' => $this->templateBase]);
        $layer->initialize($this->getContext());
        $layer->setRenderer($renderer);

        $attributes = ['title' => 'Quiote'];
        $moreAssigns = ['inner' => '<root/>'];
        $output = $layer->execute($renderer, $attributes, $moreAssigns);

        $this->assertStringContainsString('Quiote', $output);
    }

    public function testRenderThrowsWhenNoTemplateIsSet(): void
    {
        $renderer = new XsltRenderer();
        $renderer->initialize($this->getContext());

        $layer = new FileTemplateLayer();
        $layer->initialize($this->getContext());
        $layer->setRenderer($renderer);

        $this->expectException(RenderException::class);
        $this->expectExceptionMessage('No stylesheet template is set on the template layer.');

        $attributes = [];
        $slots = [];
        $moreAssigns = ['inner' => '<root/>'];
        $renderer->render($layer, $attributes, $slots, $moreAssigns);
    }

    public function testRenderThrowsWhenInnerAssignIsNotDocumentSourceCompatible(): void
    {
        $renderer = new XsltRenderer();
        $renderer->initialize($this->getContext());

        $layer = new FileTemplateLayer(['template' => $this->templateBase]);
        $layer->initialize($this->getContext());
        $layer->setRenderer($renderer);

        $this->expectException(RenderException::class);
        $this->expectExceptionMessage("The 'inner' assign must be a DOMDocument, string, or null, array given.");

        $attributes = ['name' => 'Quiote'];
        $slots = [];
        $moreAssigns = ['inner' => ['not', 'a', 'document']];
        $renderer->render($layer, $attributes, $slots, $moreAssigns);
    }

    /**
     * `document()` is the XSLT read primitive. PHP's own default security prefs
     * already block writes, but leave reads enabled, so a stylesheet -- or
     * anything a stylesheet interpolates into a document() argument -- could
     * pull an arbitrary local file into the output, or reach a URL (cloud
     * metadata endpoints being the usual target). setSecurityPrefs() closes it.
     */
    public function testDocumentCannotReadALocalFile(): void
    {
        $secret = sys_get_temp_dir() . '/quiote-xslt-renderer-test/secret.xml';
        file_put_contents($secret, '<secret>TOP-SECRET-VALUE</secret>');

        try {
            file_put_contents($this->templateBase . '.xsl', sprintf(<<<'XSL'
<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform">
    <xsl:output method="text" />
    <xsl:template match="/">[<xsl:value-of select="document('%s')/secret" />]</xsl:template>
</xsl:stylesheet>
XSL, $secret));

            $renderer = new XsltRenderer();
            $renderer->initialize($this->getContext());
            $layer = new FileTemplateLayer(['template' => $this->templateBase]);
            $layer->initialize($this->getContext());
            $layer->setRenderer($renderer);

            $attributes = [];
            $moreAssigns = ['inner' => '<root/>'];

            // libxslt treats the refused read as a hard transform failure rather
            // than as an empty node-set, so the expected outcome is a
            // RenderException. Either way the assertion that matters is the same:
            // the file's contents never reach the output.
            $output = '';
            try {
                $output = @$layer->execute($renderer, $attributes, $moreAssigns);
            } catch (RenderException $e) {
                // The refused read surfacing as a transform failure is one of the two acceptable
                // outcomes described above; $output stays empty and the assertion below still holds.
                $this->assertNotSame('', $e->getMessage());
            }

            $this->assertStringNotContainsString('TOP-SECRET-VALUE', $output, 'document() must not read local files');
        } finally {
            @unlink($secret);
        }
    }
}
