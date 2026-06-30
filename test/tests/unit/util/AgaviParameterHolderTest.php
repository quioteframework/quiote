<?php

use Agavi\Testing\AgaviPhpUnitTestCase;
use Agavi\Util\AgaviParameterHolder;
use Agavi\Util\AgaviArrayPathDefinition;

//class AgaviParameterHolderTest extends AgaviUnitTestCase
class AgaviParameterHolderTest extends AgaviPhpUnitTestCase
{
	
	public function testConstructAndGetParameters()
	{
		$data = ['foo' => 'bar', 'bar' => 'baz', 'baz' => 'qux'];
		$p = new AgaviParameterHolder($data);
		$p2 = new AgaviParameterHolder(['bla']);
		$p3 = new AgaviParameterHolder();
		$this->assertEquals([], $p3->getParameters());
		$this->assertEquals($data, $p->getParameters());
		$this->assertEquals(['bla'], $p2->getParameters());
		$this->assertEquals($data, $p->getParameters());
		$p->clearParameters();
		$this->assertEquals([], $p->getParameters());
	}

	public function testGetParametersIntegerIndex()
	{
		$data2 = ['a' => '11', 'b' => '22', 3 => '33', '44'];
		$p = new AgaviParameterHolder($data2);
		$this->assertEquals(['a' => '11', 'b' => '22', 3 => '33', '44'], $p->getParameters());
		$p->clearParameters();
		$this->assertEquals([], $p->getParameters());
	}

	public function testGetParameterNames()
	{
		$data2 = ['a' => '11', 'b' => '22', 'c' => '33'];
		$p = new AgaviParameterHolder($data2);
		$this->assertEquals(['a', 'b', 'c'], $p->getParameterNames());
		$p->clearParameters();
		$this->assertEquals([], $p->getParameters());
	}

	public function testGetParameterNamesIntegerIndex()
	{
		$data2 = ['a' => '11', 'b' => '22', 3 => '33', '44'];
		$p = new AgaviParameterHolder($data2);
		$this->assertEquals(['a', 'b', 3, 4], $p->getParameterNames());
		$p->clearParameters();
		$this->assertEquals([], $p->getParameters());
	}

	public function testGetFlatParameterNames()
	{
		$data2 = ['a' => '11', 'b' => '22', 'c' => '33'];
		$p = new AgaviParameterHolder($data2);
		$this->assertEquals(['a', 'b', 'c'], $p->getFlatParameterNames());
		$p->clearParameters();
		$this->assertEquals([], $p->getParameters());
	}

	public function testGetFlatParameterIntegerIndex()
	{
		$data2 = ['a' => '11', 'b' => '22', 3 => '33', '44'];
		$p = new AgaviParameterHolder($data2);
		$this->assertEquals(['a', 'b', 3, 4], $p->getFlatParameterNames());
		$p->clearParameters();
		$this->assertEquals([], $p->getParameters());
	}
  
	public function testGetParameter()
	{
		$data = ['stefy' => 'ecuador', 'amy' => 'florida', 'stasy' => 'ukraine', 'lalala'];
		$p = new AgaviParameterHolder($data);
		$this->assertEquals('florida', $p->getParameter('amy'));
		$this->assertEquals('', $p->getParameter('kiki'));
		$this->assertEquals('', $p->getParameter('lalala'));
		$p->clearParameters();
		$this->assertEquals([], $p->getParameters());
	}

	public function testGetParameterIntegerIndex()
	{
		$data = ['stefy' => 'ecuador', 0 => 'florida', 'lalala'];
		$p = new AgaviParameterHolder($data);
		$this->assertEquals('florida', $p->getParameter(0));
		$this->assertEquals('lalala', $p->getParameter(1));
		$p->clearParameters();
		$this->assertEquals([], $p->getParameters());
	}

	public function testHasParameter()
	{
		$data = ['stefy' => 'ecuador', 'amy' => 'florida', 'stasy' => 'ukraine', 'lalala'];
		$p = new AgaviParameterHolder($data);
		$this->assertTrue($p->hasParameter('stefy'));
		$this->assertFalse($p->hasParameter('kiki'));
		$this->assertFalse($p->hasParameter('lalala'));
		$p->clearParameters();
		$this->assertEquals([], $p->getParameters());
	}

	public function testHasParameterIntegerIndex()
	{
		$data = [1 => '111'];
		$p = new AgaviParameterHolder($data);
		$this->assertTrue($p->hasParameter(1));
		$this->assertFalse($p->hasParameter(0));
		$p->clearParameters();
		$this->assertEquals([], $p->getParameters());
	}

	public function testRemoveParameter()
	{
		$data = ['stef' => 'ecuador', 'amy' => 'florida', 'stasy' => 'ukraine', 'lalala'];
		$p = new AgaviParameterHolder($data);
		$this->assertEquals('ecuador', $p->removeParameter('stef'));
		$this->assertEquals(NULL, $p->removeParameter('kiki'));
		$this->assertEquals('lalala', $p->removeParameter(0));
		$this->assertEquals(['amy', 'stasy'], $p->getParameterNames());
		$this->assertEquals(['amy' => 'florida', 'stasy' => 'ukraine'], $p->getParameters());
		$p->clearParameters();
		$this->assertEquals([], $p->getParameters());
	}

	public function testRemoveParameterIntegerIndex()
	{
		$data = [2 => '222', 1 => '111'];
		$p = new AgaviParameterHolder($data);
		$this->assertEquals('222', $p->removeParameter(2));
		$this->assertEquals(NULL, $p->removeParameter(0));
		$this->assertEquals([1 => '111'], $p->getParameters());
		$p->clearParameters();
		$this->assertEquals([], $p->getParameters());
	}

	public function testSetParameter()
	{
		$data = ['stefy' => 'ecuador', 'amy' => 'florida', 'stasy' => 'ukraine', 'lalala'];
		$p = new AgaviParameterHolder($data);
		$p->setParameter('kiki', 'bulgaria');
		$p->setParameter('stefy', 'germany');
		$p->setParameter(0, 'ohh');
		$this->assertEquals(['stefy' => 'germany', 'amy' => 'florida', 'stasy' => 'ukraine', 'ohh', 'kiki' => 'bulgaria'], $p->getParameters());
		$p->clearParameters();
		$this->assertEquals([], $p->getParameters());
	}

	public function testSetParameterIntegerIndex()
	{
		$data = [0 => 'ecuador', 'lalala'];
		$p = new AgaviParameterHolder($data);
		$p->setParameter(0, 'bulgaria');
		$p->setParameter(1, 'germany');
		$this->assertEquals(['bulgaria', 'germany'], $p->getParameters());
		$p->clearParameters();
		$this->assertEquals([], $p->getParameters());
	}

	public function testSetParameterByRef()
	{
		$data = ['stefy' => 'ecuador', 'stasy' => 'ukraine'];
		$p = new AgaviParameterHolder($data);
		$amy = 'florida';
		$p->setParameterByRef('amy', $amy);
		// amy moves
		$amy = 'new york';
		$this->assertEquals('new york', $p->getParameter('amy'));
	}

	public function testSetGetParameterAsArray()
	{
		$p = new AgaviParameterHolder();
		$p->setParameter('foo', ['bar' => 'baz']);
		$this->assertEquals('baz', $p->getParameter('foo[bar]'));
	}

	public function testAppendParameter()
	{
		$data = ['stefy' => 'ecuador', 'amy' => 'florida', 'stasy' => 'ukraine', 'lalala'];
		$p = new AgaviParameterHolder($data);
		$kiki = 'bulgaria';
		$p->appendParameter('kiki', $kiki);
		$kiki = 'munich';
		$p->appendParameter('stefy', 'germany');
		$p->appendParameter(0, 'ohh');
		$this->assertEquals(['stefy' => ['ecuador', 'germany'], 'amy' => 'florida', 'stasy' => 'ukraine', ['lalala', 'ohh'], 'kiki' => ['bulgaria']], $p->getParameters());
		$p->appendParameter('stefy', 'sanni');
		$this->assertEquals(['stefy' => ['ecuador', 'germany', 'sanni'], 'amy' => 'florida', 'stasy' => 'ukraine', ['lalala', 'ohh'], 'kiki' => ['bulgaria']], $p->getParameters());
		$p->clearParameters();
		$this->assertEquals([], $p->getParameters());
	}

	public function testAppendParameterIntegerIndex()
	{
		$data = [0 => 'ecuador', 'lalala', 3];
		$p = new AgaviParameterHolder($data);
		$p->appendParameter(0, 'ohh');
		$this->assertEquals([0 => ['ecuador', 'ohh'], 1 => 'lalala', 2 => 3], $p->getParameters());
		$p->clearParameters();
		$this->assertEquals([], $p->getParameters());
	}

	public function testAppendParameterByRef()
	{
		$data = ['stefy' => 'ecuador', 'amy' => 'florida', 'stasy' => 'ukraine'];
		$p = new AgaviParameterHolder($data);
		$bg = 'bulgaria';
		$stefy = 'peru';
		$la = 'lalala';
		$p->appendParameterByRef('kiki', $bg);
		$p->appendParameterByRef('stefy', $stefy);
		$stefy = 'germany';
		$p->appendParameterByRef(0, $la);
		$la = 'ohh';
		$this->assertEquals(['stefy' => ['ecuador', 'germany'], 'amy' => 'florida', 'stasy' => 'ukraine', 'kiki' => ['bulgaria'], ['ohh']], $p->getParameters());
		$p->clearParameters();
		$this->assertEquals([], $p->getParameters());
	}

	public function testSetParameters()
	{
		$data = ['stefy' => 'ecuador', 'amy' => 'florida', 'stasy' => 'ukraine', 'lalala'];
		$p = new AgaviParameterHolder($data);
		$p->setParameters(['kiki' => 'bulgaria', 'stefy' => 'germany', 'ohh']);
		$this->assertEquals(['stefy' => 'germany', 'amy' => 'florida', 'stasy' => 'ukraine', 'kiki' => 'bulgaria', 'ohh'], $p->getParameters());
		$p->clearParameters();
		$this->assertEquals([], $p->getParameters());
	}

	public function testSetParametersIntegerIndex()
	{
		$data = [1 => 'ukraine', 'lalala'];
		$p = new AgaviParameterHolder($data);
		$p->setParameters(['ohh', 1 => 'london']);
		// fails in php
		$this->assertEquals(['ohh', 'london', 'lalala'], $p->getParameters());
		$p->clearParameters();
		$this->assertEquals([], $p->getParameters());
	}

	public function testSetParametersByRef()
	{
		$data = ['stefy' => 'ecuador', 'amy' => 'florida', 'stasy' => 'ukraine'];
		$p = new AgaviParameterHolder($data);
		$kiki = 'bulgaria';
		$newparameters = ['kiki' => &$kiki, 'stefy' => 'germany'];
		$p->setParametersByRef($newparameters);
		$kiki = 'munich';
		$this->assertEquals(['stefy' => 'germany', 'amy' => 'florida', 'stasy' => 'ukraine', 'kiki' => 'munich'], $p->getParameters());
		$p->clearParameters();
		$this->assertEquals([], $p->getParameters());
	}

	public function testClear()
	{
		$data3 = ['a' => '11', 'b' => '22', 'c' => '33', '44'];
		$p = new AgaviParameterHolder($data3);
		$p->clearParameters();
		$this->assertEquals([], $p->getParameters());
	}

	public function testGetSetStringInteger() {
		$p = new AgaviParameterHolder();
		$p->setParameter('10', 'ten');
		$this->assertEquals('ten', $p->getParameter(10));
		$p->setParameter(21, 'twentyone');
		$this->assertEquals('twentyone', $p->getParameter('21'));
		$p->setParameters([1 => 'one']);
		$this->assertEquals('one', $p->getParameter('1'));
		$this->assertEquals([1 => 'one', 10 => 'ten', 21 => 'twentyone'], $p->getParameters());
	}

	public function testRemoveInvalidKeyCausesNoNotice()
	{
		$ph = new AgaviParameterHolder();
		$zomg =& $ph->removeParameter('[]foo[]');
		$this->assertNull($zomg);
	}
}

?>