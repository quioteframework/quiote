<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Request\WebRequest;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;

class WebRequestTest extends UnitTestCase
{
	private WebRequest $_r;
	/** @var array<string, mixed> */
	private array $_SERVER = [];

	#[\Override]
    public function setUp(): void
	{
		$this->_SERVER = $_SERVER;
		
		$_SERVER['HTTPS'] = 'on';
		$_SERVER['SERVER_PORT'] = '123';
		$_SERVER['SERVER_NAME'] = 'example.quiote.org';
		$_SERVER['REQUEST_URI'] = '/foo/bar/baz?id=4815162342';
		
		$this->_r = new WebRequest();
		$this->_r->initialize($this->getContext());
		// Strict validation: pre-whitelist parameter names referenced in tests
		$this->_r = $this->_r->enforceValidatedParameters(['x','y','z','a','b','k','missing']);
	}
	
	public function testGetUrlScheme(): void
	{
		$this->assertEquals('https', $this->_r->getUrlScheme());
	}

	public function testGetUrlAuthority(): void
	{
		$this->assertEquals('example.quiote.org:123', $this->_r->getUrlAuthority());
	}

	public function testGetUrlPath(): void
	{
		$this->assertEquals('/foo/bar/baz', $this->_r->getUrlPath());
	}

	public function testGetUrlQuery(): void
	{
		$this->assertEquals('id=4815162342', $this->_r->getUrlQuery());
	}

	public function testGetRequestUri(): void
	{
		$this->assertEquals('/foo/bar/baz?id=4815162342', $this->_r->getRequestUri());
	}

	public function testGetUrl(): void
	{
		$this->assertEquals('https://example.quiote.org:123/foo/bar/baz?id=4815162342', $this->_r->getUrl());
	}

	// --- New tests for PSR-7 bridging & precedence ---
	public function testAttachPsrRequestMergesQueryAndBodyBodyWins(): void
	{
		$this->_r = $this->_r
			->withUri(new \Quiote\Http\SimpleUri('/x?x=1&y=2'))
			->withQueryParams(['x' => '1', 'y' => '2'])
			->withParsedBody(['y' => 'body', 'z' => '3']);
		// Ensure whitelist includes merged params (already added in setUp but safe to repeat)
		$this->_r = $this->_r->enforceValidatedParameters(['x','y','z']);
		$this->assertSame('1', $this->_r->getParameter('x'));
		$this->assertSame('body', $this->_r->getParameter('y'));
		$this->assertSame('3', $this->_r->getParameter('z'));
	}

	public function testAttachPsrRequestFormUrlEncodedRawBodyNotAutoParsed(): void
	{
		$raw = 'a=1&b=2&b=3';
		$stream = Stream::create($raw);
		$this->_r = $this->_r
			->withMethod('POST')
			->withHeader('Content-Type', 'application/x-www-form-urlencoded')
			->withBody($stream);
		$this->_r = $this->_r->enforceValidatedParameters(['a','b']);
		// Current implementation does not parse raw form body unless parsedBody already array.
		$this->assertNull($this->_r->getParameter('a'));
		$this->assertNull($this->_r->getParameter('b'));
	}

	public function testIsParameterValueEmptyAndRuntimeMutation(): void
	{
		$this->_r = $this->_r->enforceValidatedParameters(['missing','k']);
		$this->assertTrue($this->_r->isParameterValueEmpty('missing'));
		$this->_r = $this->_r->setParameter('k', '');
		$this->assertTrue($this->_r->isParameterValueEmpty('k'));
		$this->_r = $this->_r->setParameter('k', 'v');
		$this->assertFalse($this->_r->isParameterValueEmpty('k'));
		$this->_r = $this->_r->removeParameter('k');
		$this->assertTrue($this->_r->isParameterValueEmpty('k'));
	}

	public function testCookieAndHeaderEmptinessChecks(): void
	{
		$this->_r = $this->_r
			->withCookieParams(['sid' => 'abc'])
			->withHeader('X-Test', 'ok');
		$this->assertTrue($this->_r->hasCookie('sid'));
		$this->assertFalse($this->_r->isCookieValueEmpty('sid'));
		$this->assertTrue($this->_r->isHeaderValueEmpty('x-missing'));
		$this->assertFalse($this->_r->isHeaderValueEmpty('X-Test'));
	}

	public function testAttachPsrRequestPreservesBodyRewind(): void
	{
		$raw = 'm=1';
		$stream = Stream::create($raw);
		$this->_r = $this->_r
			->withMethod('POST')
			->withHeader('Content-Type', 'application/x-www-form-urlencoded')
			->withBody($stream);
		$bodyStream = $this->_r->getBody();
		if($bodyStream->isSeekable()) { $bodyStream->rewind(); }
		$this->assertSame($raw, $bodyStream->getContents());
	}

	#[\Override]
    public function tearDown(): void
	{
		$_SERVER = $this->_SERVER;
	}

}