<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Format\Xml\ElementPositionIndex;

class ElementPositionIndexTest extends PhpUnitTestCase
{
	public function testRecordedElementIsReturnedByForElement(): void
	{
		$doc = new \DOMDocument();
		$element = $doc->createElement('foo');

		$index = new ElementPositionIndex();
		$index->record($element, '/tmp/settings.xml', 42);

		$this->assertSame(['file' => '/tmp/settings.xml', 'line' => 42], $index->forElement($element));
	}

	public function testUnknownElementReturnsNull(): void
	{
		$doc = new \DOMDocument();
		$element = $doc->createElement('foo');

		$index = new ElementPositionIndex();

		$this->assertNull($index->forElement($element));
	}

	public function testDifferentElementInstancesAreIndexedIndependently(): void
	{
		$doc = new \DOMDocument();
		$a = $doc->createElement('a');
		$b = $doc->createElement('b');

		$index = new ElementPositionIndex();
		$index->record($a, 'file-a.xml', 1);
		$index->record($b, 'file-b.xml', 2);

		$this->assertSame(['file' => 'file-a.xml', 'line' => 1], $index->forElement($a));
		$this->assertSame(['file' => 'file-b.xml', 'line' => 2], $index->forElement($b));
	}
}
?>
