<?php

declare(strict_types=1);

namespace Quiote\Renderer\Xslt;

use DOMDocument;
use DOMException;
use Quiote\Exception\RenderException;
use Quiote\Renderer\IReusableRenderer;
use Quiote\Renderer\Renderer;
use Quiote\Util\ArrayPathDefinition;
use Quiote\View\TemplateLayer;
use XSLTProcessor;

/**
 * Renders `.xsl` stylesheets against an "inner" XML document (from
 * `$moreAssigns['inner']`) via ext-xsl. With the `envelope` parameter (on by
 * default) it wraps the inner document plus each rendered slot into a single
 * synthetic document under the {@see self::ENVELOPE_NAMESPACE} namespace,
 * so a stylesheet can pull slot content via XPath instead of relying on
 * XSLT string parameters (which can't carry markup).
 */
final class XsltRenderer extends Renderer implements IReusableRenderer
{
    public const string ENVELOPE_NAMESPACE = 'http://quiote.org/quiote/renderer/xslt/envelope/1.0';

    protected $defaultExtension = '.xsl';

    #[\Override]
    public function render(TemplateLayer $layer, array &$attributes = [], array &$slots = [], array &$moreAssigns = [])
    {
        $stylesheetPath = $layer->getResourceStreamIdentifier();

        try {
            $stylesheet = $this->loadXmlFromFile($stylesheetPath);
        } catch (DOMException $e) {
            throw new RenderException("Unable to load stylesheet '{$stylesheetPath}'." . "\n\n" . $e->getMessage(), 0, $e);
        }

        $processor = new XSLTProcessor();
        $processor->importStylesheet($stylesheet);

        foreach ($attributes as $name => $value) {
            if (is_scalar($value) || $value instanceof \Stringable) {
                $processor->setParameter('', $name, (string) $value);
            }
        }

        $document = $this->getParameter('envelope', true)
            ? $this->buildEnvelope($moreAssigns['inner'] ?? null, $slots, $layer)
            : $this->documentFrom($moreAssigns['inner'] ?? null);

        return $processor->transformToXML($document);
    }

    #[\Override]
    public function getStarterTemplate(): string
    {
        return <<<'XSL'
<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform">
    <xsl:output method="html" encoding="UTF-8" />
    <xsl:param name="title" />
    <xsl:template match="/">
        <p><xsl:value-of select="$title" /></p>
    </xsl:template>
</xsl:stylesheet>

XSL;
    }

    private function buildEnvelope(DOMDocument|string|null $inner, array $slots, TemplateLayer $layer): DOMDocument
    {
        $envelope = new DOMDocument();
        $envelope->appendChild($root = $envelope->createElementNS(self::ENVELOPE_NAMESPACE, 'envelope'));

        $root->appendChild($innerWrapper = $envelope->createElementNS(self::ENVELOPE_NAMESPACE, 'inner'));
        $innerWrapper->appendChild($envelope->importNode($this->documentFrom($inner)->documentElement, true));

        $root->appendChild($slotsWrapper = $envelope->createElementNS(self::ENVELOPE_NAMESPACE, 'slots'));
        foreach (ArrayPathDefinition::flatten($slots) as $slotName => $slotContent) {
            try {
                $slotDocument = $this->documentFrom($slotContent);
            } catch (DOMException $e) {
                throw new RenderException("Unable to load contents for slot '{$slotName}'." . "\n\n" . $e->getMessage(), 0, $e);
            }
            $slotsWrapper->appendChild($slotWrapper = $envelope->createElementNS(self::ENVELOPE_NAMESPACE, 'slot'));
            $slotWrapper->setAttribute('name', $slotName);
            $slotWrapper->appendChild($envelope->importNode($slotDocument->documentElement, true));
        }

        return $envelope;
    }

    private function documentFrom(DOMDocument|string|null $source): DOMDocument
    {
        if ($source instanceof DOMDocument) {
            return $source;
        }

        return $this->loadXmlFromString((string) $source);
    }

    private function loadXmlFromString(string $xml): DOMDocument
    {
        return $this->withInternalErrors(function () use ($xml) {
            $document = new DOMDocument();
            $loaded = @$document->loadXML($xml);
            return [$loaded ? $document : null, $loaded];
        });
    }

    private function loadXmlFromFile(string $path): DOMDocument
    {
        return $this->withInternalErrors(function () use ($path) {
            $document = new DOMDocument();
            $loaded = @$document->load($path);
            return [$loaded ? $document : null, $loaded];
        });
    }

    /**
     * @param callable(): array{0: ?DOMDocument, 1: bool} $loader
     */
    private function withInternalErrors(callable $loader): DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        [$document, $loaded] = $loader();
        $errors = libxml_get_errors();

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded || $document === null) {
            $messages = array_map(
                static fn(\LibXMLError $error): string => sprintf(
                    '[%s #%d] Line %d: %s',
                    match ($error->level) {
                        LIBXML_ERR_WARNING => 'Warning',
                        LIBXML_ERR_ERROR => 'Error',
                        default => 'Fatal',
                    },
                    $error->code,
                    $error->line,
                    trim($error->message),
                ),
                $errors,
            );

            throw new DOMException(
                sprintf('Error%s occurred while parsing the document:', count($messages) === 1 ? '' : 's')
                . "\n\n" . implode("\n", $messages !== [] ? $messages : ['Unknown error (document empty?)']),
            );
        }

        return $document;
    }
}
