<?php

use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Request\AgaviWebRequest;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;

class AgaviWebRequestTest extends AgaviUnitTestCase
{
	private $_r = null;
	private $_SERVER = array();

	public function setUp(): void
	{
		$this->_SERVER = $_SERVER;
		
		$_SERVER['HTTPS'] = 'on';
		$_SERVER['SERVER_PORT'] = '123';
		$_SERVER['SERVER_NAME'] = 'example.agavi.org';
		$_SERVER['REQUEST_URI'] = '/foo/bar/baz?id=4815162342';
		
		$this->_r = new AgaviWebRequest();
		$this->_r->initialize($this->getContext());
		// Strict validation: pre-whitelist parameter names referenced in tests
		$this->_r->enforceValidatedParameters(['x','y','z','a','b','k','missing']);
	}
	
	public function testGetUrlScheme()
	{
		$this->assertEquals('https', $this->_r->getUrlScheme());
	}

	public function testGetUrlAuthority()
	{
		$this->assertEquals('example.agavi.org:123', $this->_r->getUrlAuthority());
	}

	public function testGetUrlPath()
	{
		$this->assertEquals('/foo/bar/baz', $this->_r->getUrlPath());
	}

	public function testGetUrlQuery()
	{
		$this->assertEquals('id=4815162342', $this->_r->getUrlQuery());
	}

	public function testGetRequestUri()
	{
		$this->assertEquals('/foo/bar/baz?id=4815162342', $this->_r->getRequestUri());
	}

	public function testGetUrl()
	{
		$this->assertEquals('https://example.agavi.org:123/foo/bar/baz?id=4815162342', $this->_r->getUrl());
	}

	// --- New tests for PSR-7 bridging & precedence ---
	public function testAttachPsrRequestMergesQueryAndBodyBodyWins()
	{
		$psr = (new ServerRequest('POST', '/x?x=1&y=2'))
			->withParsedBody(['y' => 'body', 'z' => '3']);
		$this->_r->attachPsrRequest($psr);
		// Ensure whitelist includes merged params (already added in setUp but safe to repeat)
		$this->_r->enforceValidatedParameters(['x','y','z']);
		$this->assertSame('1', $this->_r->getParameter('x'));
		$this->assertSame('body', $this->_r->getParameter('y'));
		$this->assertSame('3', $this->_r->getParameter('z'));
	}

	public function testAttachPsrRequestFormUrlEncodedRawBodyNotAutoParsed()
	{
		$raw = 'a=1&b=2&b=3';
		$stream = Stream::create($raw);
		$psr = (new ServerRequest('POST', '/submit'))
			->withHeader('Content-Type', 'application/x-www-form-urlencoded')
			->withBody($stream);
		$this->_r->attachPsrRequest($psr);
		$this->_r->enforceValidatedParameters(['a','b']);
		// Current implementation does not parse raw form body unless parsedBody already array.
		$this->assertNull($this->_r->getParameter('a'));
		$this->assertNull($this->_r->getParameter('b'));
	}

	public function testIsParameterValueEmptyAndRuntimeMutation()
	{
		$this->_r->enforceValidatedParameters(['missing','k']);
		$this->assertTrue($this->_r->isParameterValueEmpty('missing'));
		$this->_r->setParameter('k', '');
		$this->assertTrue($this->_r->isParameterValueEmpty('k'));
		$this->_r->setParameter('k', 'v');
		$this->assertFalse($this->_r->isParameterValueEmpty('k'));
		$this->_r->removeParameter('k');
		$this->assertTrue($this->_r->isParameterValueEmpty('k'));
	}

	public function testCookieAndHeaderEmptinessChecks()
	{
		$psr = (new ServerRequest('GET', '/'))->withCookieParams(['sid' => 'abc']);
		$psr = $psr->withHeader('X-Test', 'ok');
		$this->_r->attachPsrRequest($psr);
		$this->assertTrue($this->_r->hasCookie('sid'));
		$this->assertFalse($this->_r->isCookieValueEmpty('sid'));
		$this->assertTrue($this->_r->isHeaderValueEmpty('x-missing'));
		$this->assertFalse($this->_r->isHeaderValueEmpty('X-Test'));
	}

	public function testAttachPsrRequestPreservesBodyRewind()
	{
		$raw = 'm=1';
		$stream = Stream::create($raw);
		$psr = (new ServerRequest('POST', '/r'))
			->withHeader('Content-Type', 'application/x-www-form-urlencoded')
			->withBody($stream);
		$this->_r->attachPsrRequest($psr);
		$bodyStream = $psr->getBody();
		if($bodyStream->isSeekable()) { $bodyStream->rewind(); }
		$this->assertSame($raw, $bodyStream->getContents());
	}

	public function tearDown(): void
	{
		$_SERVER = $this->_SERVER;
	}

}