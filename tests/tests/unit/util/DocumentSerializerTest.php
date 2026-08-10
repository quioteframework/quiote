<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Util\FormPopulation\DocumentSerializer;

/**
 * Writing the populated DOM back out.
 *
 * HTML needs nothing. XHTML needs three separate repairs, each for a distinct
 * way DOM's XML serializer produces output a browser or validator objects to,
 * and each only correct for a document parsed as HTML but written as XML --
 * so each is conditional, and each condition is what these pin down.
 */
final class DocumentSerializerTest extends TestCase
{
    private function documentFromHtml(string $html): DOMDocument
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }

    private function documentFromXml(string $xml): DOMDocument
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }

    /** @param array<string, mixed> $cfg */
    private function serialize(
        DOMDocument $document,
        bool $xhtml,
        array $cfg = [],
        bool $parsedAsXml = false,
        bool $properXhtml = false,
        bool $hadXmlProlog = false,
    ): string {
        return (new DocumentSerializer($document, $parsedAsXml, $properXhtml, $hadXmlProlog, true))
            ->serialize($xhtml, $cfg + ['savexml_options' => 0, 'cdata_fix' => false, 'remove_auto_xml_prolog' => false]);
    }

    // --- HTML ----------------------------------------------------------------

    public function testAnHtmlDocumentIsSerializedAsHtml(): void
    {
        $out = $this->serialize($this->documentFromHtml('<html><body><p>Text</p></body></html>'), false);

        $this->assertStringContainsString('<p>Text</p>', $out);
        $this->assertStringNotContainsString('<?xml', $out, 'HTML output carries no XML prolog');
    }

    /** None of the XHTML repairs apply to HTML output, whatever the config says. */
    public function testHtmlOutputIgnoresTheXhtmlRepairSettings(): void
    {
        $document = $this->documentFromHtml('<html><body><p>Text</p></body></html>');

        $out = $this->serialize($document, false, ['cdata_fix' => true, 'remove_auto_xml_prolog' => true]);

        $this->assertStringContainsString('<p>Text</p>', $out);
    }

    // --- the duplicate-xmlns repair -------------------------------------------

    /**
     * DOM emits two xmlns attributes on <html> when a document parsed as HTML
     * is written as XML, which is not well-formed. The repair removes and
     * recreates the attributes; the outcome to assert is that the document
     * parses back.
     */
    public function testAnHtmlParsedDocumentSerializesToWellFormedXml(): void
    {
        $document = $this->documentFromHtml(
            '<!DOCTYPE html><html xmlns="http://www.w3.org/1999/xhtml" lang="en"><body><p>Text</p></body></html>'
        );

        $out = $this->serialize($document, true);

        $reparsed = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $ok = $reparsed->loadXML($out);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->assertTrue($ok, 'the serialized document must parse as XML');
        $this->assertSame([], $errors);
        // The defect being guarded is a *duplicated* xmlns, which is not well-formed. loadHTML is
        // not namespace-aware, so the attribute does not survive the round trip at all here --
        // what matters is that it never comes back twice.
        $this->assertLessThanOrEqual(1, substr_count($out, 'xmlns='));
    }

    public function testTheRepairKeepsTheDocumentElementsOtherAttributes(): void
    {
        $document = $this->documentFromHtml(
            '<!DOCTYPE html><html xmlns="http://www.w3.org/1999/xhtml" lang="en" dir="ltr"><body>x</body></html>'
        );

        $out = $this->serialize($document, true);

        $this->assertStringContainsString('lang="en"', $out);
        $this->assertStringContainsString('dir="ltr"', $out);
    }

    /** A namespaced attribute has to come back in its namespace, not as a literal name. */
    public function testANamespacedAttributeOnTheDocumentElementSurvives(): void
    {
        $document = $this->documentFromHtml(
            '<!DOCTYPE html><html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en"><body>x</body></html>'
        );

        $out = $this->serialize($document, true);

        $this->assertStringContainsString('xml:lang="en"', $out);
    }

    /** A document parsed as XML already has its namespaces right, so it is left alone. */
    public function testAnXmlParsedDocumentIsNotRepaired(): void
    {
        $document = $this->documentFromXml(
            '<?xml version="1.0"?><html xmlns="http://www.w3.org/1999/xhtml"><body><p>Text</p></body></html>'
        );

        $out = $this->serialize($document, true, parsedAsXml: true, hadXmlProlog: true);

        $this->assertStringContainsString('<p>Text</p>', $out);
        $this->assertSame(1, substr_count($out, 'xmlns='));
    }

    // --- the CDATA repair -------------------------------------------------------

    /**
     * A CDATA section is invisible to an HTML parser, so a browser reading
     * XHTML as HTML would see the script's opening marker as text. The comment
     * dance hides the markers from HTML while keeping them for XML.
     */
    public function testTheCdataFixWrapsAScriptBlockForHtmlParsers(): void
    {
        $document = $this->documentFromXml(
            '<html xmlns="http://www.w3.org/1999/xhtml"><body><script><![CDATA[var x = 1 < 2;]]></script></body></html>'
        );

        $out = $this->serialize($document, true, ['cdata_fix' => true], parsedAsXml: true);

        $this->assertStringContainsString('<!--//--><![CDATA[//><!--', $out);
        $this->assertStringContainsString('//--><!]]>', $out);
    }

    public function testTheCdataFixWrapsAStyleBlockToo(): void
    {
        $document = $this->documentFromXml(
            '<html xmlns="http://www.w3.org/1999/xhtml"><body><style><![CDATA[a > b { color: red }]]></style></body></html>'
        );

        $out = $this->serialize($document, true, ['cdata_fix' => true], parsedAsXml: true);

        $this->assertStringContainsString('<!--/*--><![CDATA[/*><!--*/', $out);
        $this->assertStringContainsString('/*]]>*/-->', $out);
    }

    public function testTheCdataFixIsSkippedWhenNotAskedFor(): void
    {
        $document = $this->documentFromXml(
            '<html xmlns="http://www.w3.org/1999/xhtml"><body><script><![CDATA[var x = 1;]]></script></body></html>'
        );

        $out = $this->serialize($document, true, ['cdata_fix' => false], parsedAsXml: true);

        $this->assertStringNotContainsString('<!--//-->', $out);
    }

    /**
     * A document that is genuinely being served as XHTML has a real XML parser
     * at the other end, so the comment dance is unnecessary -- and would be
     * visible in the script.
     */
    public function testTheCdataFixIsSkippedForProperlyServedXhtml(): void
    {
        $document = $this->documentFromXml(
            '<html xmlns="http://www.w3.org/1999/xhtml"><body><script><![CDATA[var x = 1;]]></script></body></html>'
        );

        $out = $this->serialize($document, true, ['cdata_fix' => true], parsedAsXml: true, properXhtml: true);

        $this->assertStringNotContainsString('<!--//-->', $out);
    }

    // --- the generated-prolog repair ---------------------------------------------

    /** A document that arrived without a prolog must not leave with one. */
    public function testTheGeneratedPrologIsRemovedFromADocumentThatHadNone(): void
    {
        $document = $this->documentFromXml('<html xmlns="http://www.w3.org/1999/xhtml"><body>x</body></html>');

        $out = $this->serialize(
            $document,
            true,
            ['remove_auto_xml_prolog' => true],
            parsedAsXml: true,
            hadXmlProlog: false,
        );

        $this->assertStringNotContainsString('<?xml', $out);
    }

    /** A document that arrived with a prolog keeps it. */
    public function testAPrologTheDocumentArrivedWithIsKept(): void
    {
        $document = $this->documentFromXml(
            '<?xml version="1.0"?><html xmlns="http://www.w3.org/1999/xhtml"><body>x</body></html>'
        );

        $out = $this->serialize(
            $document,
            true,
            ['remove_auto_xml_prolog' => true],
            parsedAsXml: true,
            hadXmlProlog: true,
        );

        $this->assertStringContainsString('<?xml', $out);
    }

    /**
     * For a document parsed as HTML, DOM inserts a second prolog after the
     * DOCTYPE that ends in two question marks. It is removed even when prolog
     * removal was not requested, because it is not valid output either way.
     */
    public function testTheStrayDoubleQuestionMarkPrologIsAlwaysRemoved(): void
    {
        $document = $this->documentFromHtml(
            '<!DOCTYPE html><html xmlns="http://www.w3.org/1999/xhtml"><body>x</body></html>'
        );

        $out = $this->serialize($document, true, ['remove_auto_xml_prolog' => false], parsedAsXml: false);

        $this->assertStringNotContainsString('??>', $out);
    }
}
