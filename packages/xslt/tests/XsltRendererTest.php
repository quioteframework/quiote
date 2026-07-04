<?php

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
}
