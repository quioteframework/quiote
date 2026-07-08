<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Util\ArrayPathDefinition;
use Quiote\Util\VirtualArrayPath;
use Quiote\Util\ParameterHolder;
use Quiote\Util\AttributeHolder;

class MyQuioteAttributeHolder extends AttributeHolder {}

class AttributeHolderTest extends PhpUnitTestCase
{
	
	public function testGetDefaultNamespace(): void
	{
		$p = new MyQuioteAttributeHolder(['baz' => 'boo']);
		$this->assertEquals('org.quiote', $p->getDefaultNamespace());
	}

	public function testClearAttributes(): void
	{
		$data = ['baz' => 'boo'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data);
		$this->assertEquals($data, $p->getAttributes());
		$p->clearAttributes();
		$this->assertEquals([], $p->getAttributes());
	}

	public function testGetAndSetAttributes(): void
	{
		$data = ['baz' => 'boo'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data);
		$this->assertEquals($data, $p->getAttributes());
	}

	public function testGetAndSetAttributesWithNamespace(): void
	{
		$data = ['baz' => 'boo'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data, 'mynamespace');
		$this->assertEquals([], $p->getAttributes());
		$this->assertEquals($data, $p->getAttributes('mynamespace'));
	}

	public function testSetAttributesWithIntegerIndex(): void
	{
		$data = [1 => 'boo'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data);
		$this->assertEquals([1 => 'boo'], $p->getAttributes());
	}

	public function testGetAttribute(): void
	{
		$data = ['baz' => 'boo'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data);
		$this->assertEquals('boo', $p->getAttribute('baz'));
	}

	public function testGetAttributeWithNamespace(): void
	{
		$data = ['baz' => 'boo'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data, 'mynamespace');
		$this->assertEquals('boo', $p->getAttribute('baz', 'mynamespace'));
		$this->assertEquals(NULL, $p->getAttribute('baz'));
	}

	public function testGetAttributeFromDifferentNamespaces(): void
	{
		$data = ['baz' => 'boo'];
		$data2 = ['ben' => 'jerry'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data, 'mynamespace');
		$p->setAttributes($data2);
		$this->assertEquals('boo', $p->getAttribute('baz', 'mynamespace'));
		$this->assertEquals('jerry', $p->getAttribute('ben'));
	}

	public function testGetAttributeWithNamespaceAndDefault(): void
	{
		$data = ['baz' => 'boo'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data, 'mynamespace');
		$this->assertEquals('boo', $p->getAttribute('baz', 'mynamespace', 'beh'));
		$this->assertEquals('beh', $p->getAttribute('bla', 'mynamespace', 'beh'));
		$this->assertEquals('beh', $p->getAttribute('baz', 'anothernamespace', 'beh'));
	}

	public function testGetAttributeWithoutNamespaceAndDefault(): void
	{
		$data = ['baz' => 'boo'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data);
		$this->assertEquals('boo', $p->getAttribute('baz', null, 'beh'));
		$this->assertEquals('beh', $p->getAttribute('bla', null, 'beh'));
	}

	public function testGetAttributeWithIntegerIndex(): void
	{
		$data = [2 => 'boo'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data);
		$this->assertEquals('boo', $p->getAttribute("2"));
	}

	public function testHasAttribute(): void
	{
		$data = ['baz' => 'boo'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data);
		$this->assertTrue($p->hasAttribute('baz'));
		$this->assertFalse($p->hasAttribute('boo'));
		$p->clearAttributes();
		$this->assertEquals([], $p->getAttributes());
	}

	public function testHasAttributeWithIntegerIndex(): void
	{
		$data = [2 => 'boo'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data);
		//reindexing made my php
		$this->assertTrue($p->hasAttribute(2));
		$this->assertFalse($p->hasAttribute(0));
	}

	public function testHasAttributeWithNamespace(): void
	{
		$data = ['baz' => 'boo'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data, 'mynamespace');
		$this->assertTrue($p->hasAttribute('baz', 'mynamespace'));
		$this->assertFalse($p->hasAttribute('boo', 'mynamespace'));
		$this->assertFalse($p->hasAttribute('baz'));
	}

	public function testGetAttributeNames(): void
	{
		$data = ['baz' => 'boo', 'flip' => 'flop'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data);
		$this->assertEquals(['baz', 'flip'], $p->getAttributeNames());
		$p->clearAttributes();
		$this->assertEquals([], $p->getAttributes());
	}

	public function testGetAttributeNamesWithNamespace(): void
	{
		$data = ['baz' => 'boo', 'flip' => 'flop'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data, 'mynamespace');
		$this->assertEquals(NULL, $p->getAttributeNames());
		$this->assertEquals(['baz', 'flip'], $p->getAttributeNames('mynamespace'));
		$p->clearAttributes();
		$this->assertEquals([], $p->getAttributes());
	}

	public function testGetFlatAttributeNames(): void
	{
		$data = ['baz' => 'boo', 'flip' => 'flop'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data);
		$this->assertEquals(['baz', 'flip'], $p->getFlatAttributeNames());
	}

	public function testGetFlatAttributeNamesWithNamespace(): void
	{
		$data = ['baz' => 'boo', 'flip' => 'flop'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data, 'mynamespace');
		$this->assertEquals(NULL, $p->getFlatAttributeNames());
		$this->assertEquals(['baz', 'flip'], $p->getFlatAttributeNames('mynamespace'));
	}

	public function testGetAttributes(): void
	{
		$data = ['baz' => 'boo', 'flip' => 'flop'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data, 'mynamespace');
		$this->assertEquals($data, $p->getAttributes('mynamespace'));
	}

	public function testGetAttributesEmpty(): void
	{
		$data = ['baz' => 'boo', 'flip' => 'flop'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data, 'mynamespace');
		$this->assertEquals([], $p->getAttributes('namespace'));
	}

	public function testGetAttributeNamespace(): void
	{
		$data = ['baz' => 'boo', 'flip' => 'flop'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data, 'mynamespace');
		$this->assertEquals($data, $p->getAttributeNamespace('mynamespace'));
	}

	public function testGetAttributeNamespaceDefault(): void
	{
		$data = ['baz' => 'boo', 'flip' => 'flop'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data);
		$this->assertEquals($data, $p->getAttributeNamespace());
	}

	public function testGetAttributeNamespaceEmpty(): void
	{
		$data = ['baz' => 'boo', 'flip' => 'flop'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data, 'mynamespace');
		$this->assertEquals(NULL, $p->getAttributeNamespace('namespace'));
	}

	public function testGetAttributeNamespaces(): void
	{
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes(['flip' => 'flop'], 'one');
		$p->setAttributes(['bus' => 'car'], 'one');
		$p->setAttributes(['infi' => 'nity'], 'two');
		$this->assertEquals(['one', 'two'], $p->getAttributeNamespaces());
	}

	public function testRemoveAttribute(): void
	{
		$data = ['baz' => 'boo'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data);
		$this->assertEquals('boo', $p->removeAttribute('baz'));
		$this->assertEquals(NULL, $p->removeAttribute('boo'));
		$p->clearAttributes();
		$this->assertEquals([], $p->getAttributes());
	}

	public function testRemoveAttributeWithIntegerIndex(): void
	{
		$data = ['2' => 'boo'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data);
		$this->assertEquals('boo', $p->removeAttribute(2));
	}

	public function testRemoveAttributeWithNamespace(): void
	{
		$data = ['baz' => 'boo'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data, 'mynamespace');
		$this->assertEquals('boo', $p->removeAttribute('baz', 'mynamespace'));
		$this->assertEquals(NULL, $p->removeAttribute('boo', 'mynamespace'));
		$this->assertEquals(NULL, $p->removeAttribute('baz'));
	}

	public function testSetAttribute(): void
	{
		$p = new MyQuioteAttributeHolder();
		$p->setAttribute('baz', 'boo');
		$this->assertTrue($p->hasAttribute('baz'));
		$this->assertEquals('boo', $p->getAttribute('baz'));
	}

	public function testSetAttributeWithIntegerIndex(): void
	{
		$p = new MyQuioteAttributeHolder();
		$p->setAttribute(1, 'boo');
		$this->assertTrue($p->hasAttribute('1'));
		$this->assertEquals('boo', $p->getAttribute(1));
	}

	public function testSetAttributeWithNamespace(): void
	{
		$p = new MyQuioteAttributeHolder();
		$p->setAttribute('baz', 'boo', 'namespace');
		$this->assertTrue($p->hasAttribute('baz', 'namespace'));
		$this->assertFalse($p->hasAttribute('baz'));
		$this->assertEquals('boo', $p->getAttribute('baz', 'namespace'));
	}

	public function testHasAttributeNamespace(): void
	{
		$data = ['baz' => 'boo', 'flip' => 'flop'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data, 'mynamespace');
		$this->assertTrue($p->hasAttributeNamespace('mynamespace'));
		$this->assertFalse($p->hasAttributeNamespace('namespace'));
	}

	public function testRemoveAttributeNamespace(): void
	{
		$data = ['baz' => 'boo', 'flip' => 'flop'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data, 'mynamespace');
		$this->assertEquals($data, $p->removeAttributeNamespace('mynamespace'));
		$this->assertFalse($p->hasAttributeNamespace('mynamespace'));
		$this->assertEquals(NULL, $p->getAttributeNamespace('mynamespace'));
		$this->assertEquals(NULL, $p->removeAttributeNamespace('mynamespace'));
	}

	public function testAppendAttribute(): void
	{
		$data = ['baz' => 'boo', 'flip' => 'flop'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data);
		$this->assertEquals($data, $p->getAttributes());
		$p->appendAttribute('flip', 'flap');
		$this->assertEquals(['baz' => 'boo', 'flip' => ['flop', 'flap']], $p->getAttributes());
		$p->appendAttribute('flip', 'flap', 'none');
		$this->assertEquals(['baz' => 'boo', 'flip' => ['flop', 'flap']], $p->getAttributes());
		$this->assertEquals(['flip' => ['flap']], $p->getAttributes('none'));
	}

	public function testAppendAttributeWithNamespace(): void
	{
		$data = ['baz' => 'boo', 'flip' => 'flop'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data, 'mynamespace');
		$this->assertEquals($data, $p->getAttributes('mynamespace'));
		$p->appendAttribute('hakuna', 'matata', 'mynamespace');
		$this->assertEquals(['baz' => 'boo', 'flip' => 'flop', 'hakuna' => ['matata']], $p->getAttributes('mynamespace'));
		$p->appendAttribute('hakuna', 'tie', 'mynamespace');
		$this->assertEquals(['baz' => 'boo', 'flip' => 'flop', 'hakuna' => ['matata', 'tie']], $p->getAttributes('mynamespace'));
	}

	public function testSetAttributeByRef(): void
	{
		$p = new MyQuioteAttributeHolder();
		$baz = 'boo';
		$p->setAttributeByRef('baz', $baz);
		$baz = 'safi';
		$this->assertTrue($p->hasAttribute('baz'));
		$this->assertEquals('safi', $p->getAttribute('baz'));
	}

	public function testSetAttributeByRefWithIntegerIndex(): void
	{
		$p = new MyQuioteAttributeHolder();
		$baz = 'boo';
		$p->setAttributeByRef(1, $baz);
		$baz = 'safi';
		$this->assertTrue($p->hasAttribute(1));
		$this->assertEquals('safi', $p->getAttribute(1));
	}

	public function testSetAttributeByRefWithNamespace(): void
	{
		$p = new MyQuioteAttributeHolder();
		$p->setAttribute('baz', 'stg', 'namespace');
		$baz = 'boo';
		$p->setAttributeByRef('baz', $baz, 'namespace');
		$baz = 'safi';
		$this->assertTrue($p->hasAttribute('baz', 'namespace'));
		$this->assertFalse($p->hasAttribute('baz'));
		$this->assertEquals('safi', $p->getAttribute('baz', 'namespace'));
	}

	public function testAppendAttributeByRef(): void
	{
		$data = ['baz' => 'boo', 'flip' => 'flop'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data);
		$this->assertEquals($data, $p->getAttributes());
		$flap = 'flep';
		$p->appendAttributeByRef('flip', $flap);
		$flap = 'flap';
		$this->assertEquals(['baz' => 'boo', 'flip' => ['flop', 'flap']], $p->getAttributes());
		$swah = 'mambo';
		$p->appendAttributeByRef('flip', $swah, 'none');
		$this->assertEquals(['flip' => ['mambo']], $p->getAttributes('none'));
		$swah = 'vipi';
		$this->assertEquals(['baz' => 'boo', 'flip' => ['flop', 'flap']], $p->getAttributes());
		$this->assertEquals(['flip' => ['vipi']], $p->getAttributes('none'));
	}

	public function testSetAttributesByRef(): void
	{
		$p = new MyQuioteAttributeHolder();
		$baz = 'boo';
		$data = ['baz' => &$baz];
		$p->setAttributesByRef($data);
		$this->assertEquals('boo', $p->getAttribute('baz'));
		$baz = 'coo';
		$this->assertEquals('coo', $p->getAttribute('baz'));
		$p->clearAttributes();
		$this->assertEquals([], $p->getAttributes());
	}

	public function testSetAttributesByRefWithNamespace(): void
	{
		$baz = 'boo';
		$data = ['baz' => &$baz];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributesByRef($data, 'mynamespace');
		$this->assertEquals('boo', $p->getAttribute('baz', 'mynamespace'));
		$baz = 'coo';
		$this->assertEquals('coo', $p->getAttribute('baz', 'mynamespace'));
	}

	public function testRemoveReturnsByReference(): void
	{
		$one = 'two';
		$omg = ['foo' => 'bar', 'bar' => 'baz'];
		$foo =& $omg['foo'];
		
		$ph = new MyQuioteAttributeHolder();
		
		$ph->setAttributeByRef('one', $one);
		$two =& $ph->removeAttribute('one');
		$two = 'six';
		$this->assertEquals('six', $one);
		
		$ph->setAttributeByRef('omg', $omg);
		$omgfoo =& $ph->removeAttribute('omg[foo]');
		$omgfoo = 'baz';
		$this->assertEquals('baz', $foo);
	}
}

?>
