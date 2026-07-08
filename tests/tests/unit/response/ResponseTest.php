<?php

use Quiote\Controller\OutputType;
use Quiote\Testing\UnitTestCase;
use Quiote\Response\WebResponse;

class SampleResponse extends WebResponse
{
	#[\Override]
    public function clear()
	{
	}

	#[\Override]
    public function send(?OutputType $ot = null)
	{
	}
	
	#[\Override]
    public function setRedirect($location, $code = 302)
	{
	}
	
	#[\Override]
    public function getRedirect()
	{
		return null;
	}

	#[\Override]
    public function hasRedirect()
	{
		return false;
	}
	
	#[\Override]
    public function clearRedirect()
	{
	}
	
	#[\Override]
    public function merge($other)
	{
	}
}

class ResponseTest extends UnitTestCase
{
	private SampleResponse $_r;

	#[\Override]
    public function setUp(): void
	{
		$this->_r = new SampleResponse();
		$this->_r->initialize($this->getContext());
	}

	public function testGetContext(): void
	{
		$ctx = $this->getContext();
		$ctx_test = $this->_r->getContext();
		$this->assertSame($ctx, $ctx_test);
	}

	public function testSetGetContent(): void
	{
		$r = $this->_r;
		$this->assertEquals('', $r->getContent());
		$r->setContent('test1');
		$this->assertEquals('test1', $r->getContent());
	}

	public function testPrependContent(): void
	{
		$r = $this->_r;

		$r->setContent('content a');
		$r->prependContent('content b');
		$this->assertEquals('content b' . 'content a', $r->getContent());
	}

	public function testAppendContent(): void
	{
		$r = $this->_r;

		$r->setContent('content a');
		$r->appendContent('content b');
		$this->assertEquals('content a' . 'content b', $r->getContent());
	}
}

?>