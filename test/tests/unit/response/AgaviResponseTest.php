<?php

use Agavi\Controller\AgaviOutputType;
use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Response\AgaviWebResponse;

class SampleResponse extends AgaviWebResponse
{
	public function clear()
	{
	}

	public function send(?AgaviOutputType $ot = null)
	{
	}
	
	public function setRedirect($location, $code = 302)
	{
	}
	
	public function getRedirect()
	{
	}
	
	public function hasRedirect()
	{
	}
	
	public function clearRedirect()
	{
	}
	
	public function merge($other)
	{
	}
}

class AgaviResponseTest extends AgaviUnitTestCase
{
	private $_r = null;

	public function setUp(): void
	{
		$this->_r = new SampleResponse();
		$this->_r->initialize($this->getContext());
	}

	public function testGetContext()
	{
		$ctx = $this->getContext();
		$ctx_test = $this->_r->getContext();
		$this->assertSame($ctx, $ctx_test);
	}

	public function testSetGetContent()
	{
		$r = $this->_r;
		$this->assertEquals('', $r->getContent());
		$r->setContent('test1');
		$this->assertEquals('test1', $r->getContent());
	}

	public function testPrependContent()
	{
		$r = $this->_r;

		$r->setContent('content a');
		$r->prependContent('content b');
		$this->assertEquals('content b' . 'content a', $r->getContent());
	}

	public function testAppendContent()
	{
		$r = $this->_r;

		$r->setContent('content a');
		$r->appendContent('content b');
		$this->assertEquals('content a' . 'content b', $r->getContent());
	}
}

?>