<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Util\ArrayPathDefinition;
use Quiote\Util\VirtualArrayPath;
use Quiote\Util\ParameterHolder;
use Quiote\Util\AttributeHolder;

class MyQuioteAttributeHolder extends AttributeHolder {}

class AttributeHolderTest extends PhpUnitTestCase
{
	
	public function testGetDefaultNamespace()
	{
		$p = new MyQuioteAttributeHolder(['baz' => 'boo']);
		$this->assertEquals('org.quiote', $p->getDefaultNamespace());
	}

	public function testClearAttributes()
	{
		$data = ['baz' => 'boo'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data);
		$this->assertEquals($data, $p->getAttributes());
		$p->clearAttributes();
		$this->assertEquals([], $p->getAttributes());
	}

	public function testGetAndSetAttributes()
	{
		$data = ['baz' => 'boo'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data);
		$this->assertEquals($data, $p->getAttributes());
	}

	public function testGetAndSetAttributesWithNamespace()
	{
		$data = ['baz' => 'boo'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data, 'mynamespace');
		$this->assertEquals([], $p->getAttributes());
		$this->assertEquals($data, $p->getAttributes('mynamespace'));
	}

	public function testSetAttributesWithIntegerIndex()
	{
		$data = [1 => 'boo'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data);
		$this->assertEquals([1 => 'boo'], $p->getAttributes());
	}

	public function testGetAttribute()
	{
		$data = ['baz' => 'boo'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data);
		$this->assertEquals('boo', $p->getAttribute('baz'));
	}

	public function testGetAttributeWithNamespace()
	{
		$data = ['baz' => 'boo'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data, 'mynamespace');
		$this->assertEquals('boo', $p->getAttribute('baz', 'mynamespace'));
		$this->assertEquals(NULL, $p->getAttribute('baz'));
	}

	public function testGetAttributeFromDifferentNamespaces()
	{
		$data = ['baz' => 'boo'];
		$data2 = ['ben' => 'jerry'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data, 'mynamespace');
		$p->setAttributes($data2);
		$this->assertEquals('boo', $p->getAttribute('baz', 'mynamespace'));
		$this->assertEquals('jerry', $p->getAttribute('ben'));
	}

	public function testGetAttributeWithNamespaceAndDefault()
	{
		$data = ['baz' => 'boo'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data, 'mynamespace');
		$this->assertEquals('boo', $p->getAttribute('baz', 'mynamespace', 'beh'));
		$this->assertEquals('beh', $p->getAttribute('bla', 'mynamespace', 'beh'));
		$this->assertEquals('beh', $p->getAttribute('baz', 'anothernamespace', 'beh'));
	}

	public function testGetAttributeWithoutNamespaceAndDefault()
	{
		$data = ['baz' => 'boo'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data);
		$this->assertEquals('boo', $p->getAttribute('baz', null, 'beh'));
		$this->assertEquals('beh', $p->getAttribute('bla', null, 'beh'));
	}

	public function testGetAttributeWithIntegerIndex()
	{
		$data = [2 => 'boo'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data);
		$this->assertEquals('boo', $p->getAttribute("2"));
	}

	public function testHasAttribute()
	{
		$data = ['baz' => 'boo'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data);
		$this->assertTrue($p->hasAttribute('baz'));
		$this->assertFalse($p->hasAttribute('boo'));
		$p->clearAttributes();
		$this->assertEquals([], $p->getAttributes());
	}

	public function testHasAttributeWithIntegerIndex()
	{
		$data = [2 => 'boo'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data);
		//reindexing made my php
		$this->assertTrue($p->hasAttribute(2));
		$this->assertFalse($p->hasAttribute(0));
	}

	public function testHasAttributeWithNamespace()
	{
		$data = ['baz' => 'boo'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data, 'mynamespace');
		$this->assertTrue($p->hasAttribute('baz', 'mynamespace'));
		$this->assertFalse($p->hasAttribute('boo', 'mynamespace'));
		$this->assertFalse($p->hasAttribute('baz'));
	}

	public function testGetAttributeNames()
	{
		$data = ['baz' => 'boo', 'flip' => 'flop'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data);
		$this->assertEquals(['baz', 'flip'], $p->getAttributeNames());
		$p->clearAttributes();
		$this->assertEquals([], $p->getAttributes());
	}

	public function testGetAttributeNamesWithNamespace()
	{
		$data = ['baz' => 'boo', 'flip' => 'flop'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data, 'mynamespace');
		$this->assertEquals(NULL, $p->getAttributeNames());
		$this->assertEquals(['baz', 'flip'], $p->getAttributeNames('mynamespace'));
		$p->clearAttributes();
		$this->assertEquals([], $p->getAttributes());
	}

	public function testGetFlatAttributeNames()
	{
		$data = ['baz' => 'boo', 'flip' => 'flop'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data);
		$this->assertEquals(['baz', 'flip'], $p->getFlatAttributeNames());
	}

	public function testGetFlatAttributeNamesWithNamespace()
	{
		$data = ['baz' => 'boo', 'flip' => 'flop'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data, 'mynamespace');
		$this->assertEquals(NULL, $p->getFlatAttributeNames());
		$this->assertEquals(['baz', 'flip'], $p->getFlatAttributeNames('mynamespace'));
	}

	public function testGetAttributes()
	{
		$data = ['baz' => 'boo', 'flip' => 'flop'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data, 'mynamespace');
		$this->assertEquals($data, $p->getAttributes('mynamespace'));
	}

	public function testGetAttributesEmpty()
	{
		$data = ['baz' => 'boo', 'flip' => 'flop'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data, 'mynamespace');
		$this->assertEquals([], $p->getAttributes('namespace'));
	}

	public function testGetAttributeNamespace()
	{
		$data = ['baz' => 'boo', 'flip' => 'flop'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data, 'mynamespace');
		$this->assertEquals($data, $p->getAttributeNamespace('mynamespace'));
	}

	public function testGetAttributeNamespaceDefault()
	{
		$data = ['baz' => 'boo', 'flip' => 'flop'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data);
		$this->assertEquals($data, $p->getAttributeNamespace());
	}

	public function testGetAttributeNamespaceEmpty()
	{
		$data = ['baz' => 'boo', 'flip' => 'flop'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data, 'mynamespace');
		$this->assertEquals(NULL, $p->getAttributeNamespace('namespace'));
	}

	public function testGetAttributeNamespaces()
	{
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes(['flip' => 'flop'], 'one');
		$p->setAttributes(['bus' => 'car'], 'one');
		$p->setAttributes(['infi' => 'nity'], 'two');
		$this->assertEquals(['one', 'two'], $p->getAttributeNamespaces());
	}

	public function testRemoveAttribute()
	{
		$data = ['baz' => 'boo'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data);
		$this->assertEquals('boo', $p->removeAttribute('baz'));
		$this->assertEquals(NULL, $p->removeAttribute('boo'));
		$p->clearAttributes();
		$this->assertEquals([], $p->getAttributes());
	}

	public function testRemoveAttributeWithIntegerIndex()
	{
		$data = ['2' => 'boo'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data);
		$this->assertEquals('boo', $p->removeAttribute(2));
	}

	public function testRemoveAttributeWithNamespace()
	{
		$data = ['baz' => 'boo'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data, 'mynamespace');
		$this->assertEquals('boo', $p->removeAttribute('baz', 'mynamespace'));
		$this->assertEquals(NULL, $p->removeAttribute('boo', 'mynamespace'));
		$this->assertEquals(NULL, $p->removeAttribute('baz'));
	}

	public function testSetAttribute()
	{
		$p = new MyQuioteAttributeHolder();
		$p->setAttribute('baz', 'boo');
		$this->assertTrue($p->hasAttribute('baz'));
		$this->assertEquals('boo', $p->getAttribute('baz'));
	}

	public function testSetAttributeWithIntegerIndex()
	{
		$p = new MyQuioteAttributeHolder();
		$p->setAttribute(1, 'boo');
		$this->assertTrue($p->hasAttribute('1'));
		$this->assertEquals('boo', $p->getAttribute(1));
	}

	public function testSetAttributeWithNamespace()
	{
		$p = new MyQuioteAttributeHolder();
		$p->setAttribute('baz', 'boo', 'namespace');
		$this->assertTrue($p->hasAttribute('baz', 'namespace'));
		$this->assertFalse($p->hasAttribute('baz'));
		$this->assertEquals('boo', $p->getAttribute('baz', 'namespace'));
	}

	public function testHasAttributeNamespace()
	{
		$data = ['baz' => 'boo', 'flip' => 'flop'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data, 'mynamespace');
		$this->assertTrue($p->hasAttributeNamespace('mynamespace'));
		$this->assertFalse($p->hasAttributeNamespace('namespace'));
	}

	public function testRemoveAttributeNamespace()
	{
		$data = ['baz' => 'boo', 'flip' => 'flop'];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributes($data, 'mynamespace');
		$this->assertEquals($data, $p->removeAttributeNamespace('mynamespace'));
		$this->assertFalse($p->hasAttributeNamespace('mynamespace'));
		$this->assertEquals(NULL, $p->getAttributeNamespace('mynamespace'));
		$this->assertEquals(NULL, $p->removeAttributeNamespace('mynamespace'));
	}

	public function testAppendAttribute()
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

	public function testAppendAttributeWithNamespace()
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

	public function testSetAttributeByRef()
	{
		$p = new MyQuioteAttributeHolder();
		$baz = 'boo';
		$p->setAttributeByRef('baz', $baz);
		$baz = 'safi';
		$this->assertTrue($p->hasAttribute('baz'));
		$this->assertEquals('safi', $p->getAttribute('baz'));
	}

	public function testSetAttributeByRefWithIntegerIndex()
	{
		$p = new MyQuioteAttributeHolder();
		$baz = 'boo';
		$p->setAttributeByRef(1, $baz);
		$baz = 'safi';
		$this->assertTrue($p->hasAttribute(1));
		$this->assertEquals('safi', $p->getAttribute(1));
	}

	public function testSetAttributeByRefWithNamespace()
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

	public function testAppendAttributeByRef()
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

	public function testSetAttributesByRef()
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

	public function testSetAttributesByRefWithNamespace()
	{
		$baz = 'boo';
		$data = ['baz' => &$baz];
		$p = new MyQuioteAttributeHolder();
		$p->setAttributesByRef($data, 'mynamespace');
		$this->assertEquals('boo', $p->getAttribute('baz', 'mynamespace'));
		$baz = 'coo';
		$this->assertEquals('coo', $p->getAttribute('baz', 'mynamespace'));
	}

	public function testRemoveReturnsByReference()
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
