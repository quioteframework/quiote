<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Util\DOM\XmlConfigDomDocument;
use Quiote\Config\Util\DOM\XmlConfigDomElement;

class XmlConfigDomElementTest extends PhpUnitTestCase
{
	/**
	 * Kept as an instance property (not a local variable) so the
	 * XmlConfigDomDocument wrapper stays alive for the lifetime of the test.
	 * Letting it go out of scope while an XmlConfigDomElement obtained from it
	 * is still in use causes the DOM extension to rebuild a fresh document
	 * wrapper on the next ->ownerDocument access -- one that never went through
	 * loadXml(), so its $xpath property is null again.
	 */
	private ?XmlConfigDomDocument $document = null;

	private function loadElement(string $xml): XmlConfigDomElement
	{
		$this->document = new XmlConfigDomDocument();
		$this->document->loadXML($xml);
		$element = $this->document->documentElement;
		if (!$element instanceof XmlConfigDomElement) {
			$this->fail('Expected the root element to be an XmlConfigDomElement.');
		}
		return $element;
	}

	#[\Override]
	protected function tearDown(): void
	{
		$this->document = null;
		parent::tearDown();
	}

	public function testGetLiteralValueConvertsBooleanLiteral(): void
	{
		$root = $this->loadElement('<root>true</root>');
		$this->assertSame(true, $root->getLiteralValue());
	}

	public function testGetLiteralValueConvertsNumericLiteral(): void
	{
		$root = $this->loadElement('<root>42</root>');
		$this->assertSame(42, $root->getLiteralValue());
	}

	public function testGetLiteralValueReturnsNullForEmptyElement(): void
	{
		$root = $this->loadElement('<root></root>');
		$this->assertNull($root->getLiteralValue());
	}

	public function testGetLiteralValuePreservesPlainString(): void
	{
		$root = $this->loadElement('<root>hello world</root>');
		$this->assertSame('hello world', $root->getLiteralValue());
	}

	public function testCountChildrenCountsMatchingElements(): void
	{
		$root = $this->loadElement('<root><item/><item/><other/></root>');
		$this->assertSame(2, $root->countChildren('item'));
		$this->assertSame(1, $root->countChildren('other'));
		$this->assertSame(0, $root->countChildren('missing'));
	}

	public function testHasChildrenReflectsCountChildren(): void
	{
		$root = $this->loadElement('<root><item/></root>');
		$this->assertTrue($root->hasChildren('item'));
		$this->assertFalse($root->hasChildren('missing'));
	}
}
