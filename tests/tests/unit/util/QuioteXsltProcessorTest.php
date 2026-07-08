<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Util\QuioteXsltProcessor;

class QuioteXsltProcessorTest extends PhpUnitTestCase
{
	private const string STYLESHEET = <<<'XSL'
<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform">
	<xsl:template match="/">
		<result><xsl:value-of select="/root/@value" /></result>
	</xsl:template>
</xsl:stylesheet>
XSL;

	private function makeProcessor(): QuioteXsltProcessor
	{
		$stylesheet = new DOMDocument();
		$stylesheet->loadXML(self::STYLESHEET);

		$processor = new QuioteXsltProcessor();
		$processor->importStylesheet($stylesheet);

		return $processor;
	}

	public function testTransformToDocAcceptsADomDocumentAndReturnsADomDocument(): void
	{
		$doc = new DOMDocument();
		$doc->loadXML('<root value="hello"/>');

		$result = $this->makeProcessor()->transformToDoc($doc);

		$this->assertInstanceOf(DOMDocument::class, $result);
		$this->assertStringContainsString('hello', (string) $result->saveXML());
	}

	/**
	 * SimpleXMLElement has no ownerDocument property (unlike DOMNode); the
	 * transformToDoc() implementation used to read that nonexistent property
	 * to pick the class of the result document, which could not possibly
	 * produce a usable DOMDocument. It must always fall back to a plain
	 * DOMDocument for non-DOMDocument input instead.
	 */
	public function testTransformToDocAcceptsASimpleXmlElementAndReturnsADomDocument(): void
	{
		$doc = new SimpleXMLElement('<root value="hello"/>');

		$result = $this->makeProcessor()->transformToDoc($doc);

		$this->assertInstanceOf(DOMDocument::class, $result);
		$this->assertStringContainsString('hello', (string) $result->saveXML());
	}

	public function testTransformToDocThrowsForAnUnsupportedInputType(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		$this->makeProcessor()->transformToDoc('not a document');
	}
}
