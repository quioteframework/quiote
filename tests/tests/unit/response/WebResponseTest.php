<?php

use Quiote\Controller\OutputType;
use Quiote\Exception\QuioteException;
use Quiote\Testing\UnitTestCase;
use Quiote\Response\WebResponse;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

class WebResponseTest extends UnitTestCase
{

	/**
	 * @var \Quiote\Response\WebResponse
	 */
	private $_r = null;

	#[\Override]
    public function setUp(): void
	{
		$this->_r = new WebResponse();
		$this->_r->initialize($this->getContext());
	}

	/**
	 * send() stages rather than emits, so the content is reachable on the staged
	 * PSR-7 response instead of on the output buffer. Previously this class needed a
	 * WebResponse subclass just to swallow the "headers already sent" warnings that
	 * send()'s header() calls produced under PHPUnit -- there is nothing left to
	 * swallow now that transport belongs to the runtime's emitter.
	 */
	public function testSendStagesThePsrResponseInsteadOfEmitting(): void
	{
		$r = $this->_r;

		$r->setContent('content');

		ob_start();
		$r->send();
		$echoed = ob_get_clean();

		$this->assertSame('', $echoed, 'send() must not write to any output channel of its own');
		$this->assertTrue($r->hasStagedResponse());
		$staged = $r->getStagedResponse();
		$this->assertNotNull($staged);
		$this->assertSame('content', (string) $staged->getBody());
	}

	public function testToPsrResponseCarriesStatusHeadersAndCookies(): void
	{
		$r = $this->_r;

		$r->setContent('body');
		$r->setHttpStatusCode(404);
		$r->setHttpHeader('X-Thing', 'value');
		$r->setCookie('sid', 'abc');

		$psr = $r->toPsrResponse();

		$this->assertSame(404, $psr->getStatusCode());
		$this->assertSame('value', $psr->getHeaderLine('X-Thing'));
		$this->assertSame('body', (string) $psr->getBody());
		$this->assertNotSame([], $psr->getHeader('Set-Cookie'));
		$this->assertStringStartsWith('sid=abc', $psr->getHeader('Set-Cookie')[0]);
	}

	public function testStagedResponseIsClearedByReset(): void
	{
		$r = $this->_r;
		$r->setContent('content');
		$r->send();
		$this->assertTrue($r->hasStagedResponse());

		$r->reset();

		$this->assertFalse(
			$r->hasStagedResponse(),
			'a staged response must not survive into the next request a worker serves',
		);
	}

	public function testExposeQuioteVersionReadsCorePrefixedKey(): void
	{
		$wasSet = \Quiote\Config\Config::has('core.expose_quiote_version');
		$original = \Quiote\Config\Config::getBool('core.expose_quiote_version');
		try {
			\Quiote\Config\Config::set('core.expose_quiote_version', true);
			$r = $this->_r;
			$r->setContent('content');
			ob_start();
			try {
				$r->send();
			} catch (\Exception) {
				// discard exception about headers already sent
			}
			ob_end_clean();

			$expected = \Quiote\Config\Config::getString('quiote.release');
			if (ini_get('expose_php')) {
				$expected .= ' on PHP/' . PHP_VERSION;
			}
			$this->assertSame(
				[$expected],
				$r->getHttpHeader('X-Powered-By'),
				'core.expose_quiote_version=true should expose the release string'
			);
		} finally {
			if ($wasSet) {
				\Quiote\Config\Config::set('core.expose_quiote_version', $original);
			} else {
				\Quiote\Config\Config::remove('core.expose_quiote_version');
			}
		}
	}

	public function testExposeQuioteVersionFalseHidesVersion(): void
	{
		$wasSet = \Quiote\Config\Config::has('core.expose_quiote_version');
		$original = \Quiote\Config\Config::getBool('core.expose_quiote_version');
		try {
			\Quiote\Config\Config::set('core.expose_quiote_version', false);
			$r = $this->_r;
			$r->setContent('content');
			ob_start();
			try {
				$r->send();
			} catch (\Exception) {
				// discard exception about headers already sent
			}
			ob_end_clean();

			$expected = \Quiote\Config\Config::getString('quiote.name');
			if (ini_get('expose_php')) {
				$expected .= ' on PHP/' . PHP_VERSION;
			}
			$this->assertSame(
				[$expected],
				$r->getHttpHeader('X-Powered-By'),
				'core.expose_quiote_version=false should expose only the product name'
			);
		} finally {
			if ($wasSet) {
				\Quiote\Config\Config::set('core.expose_quiote_version', $original);
			} else {
				\Quiote\Config\Config::remove('core.expose_quiote_version');
			}
		}
	}

	public function testClear(): void
	{
		$r = $this->_r;

		$r->setContent('content');
		$r->setCookie('cookie', 'value', 'lt1', 'p1', 'd1', false);
		$r->setHttpHeader('header', 'value');

		$this->assertEquals('content', $r->getContent());
		$r->clear();
		$this->assertEquals('', $r->getContent());
		$this->assertEquals([], $r->getHttpHeaders());
		$this->assertEquals([], $r->getCookies());
	}

	public function testSetGetContentType(): void
	{
		$r = $this->_r;
		$this->assertNull($r->getContentType());

		$r->setContentType('text/html');
		$this->assertEquals('text/html', $r->getContentType());

		$r->setContentType('text/xml');
		$this->assertEquals('text/xml', $r->getContentType());
	}

	public function testSetGetHttpStatusCode(): void
	{
		$r = $this->_r;

		$this->assertEquals('200', $r->getHttpStatusCode());
		$r->setHttpStatusCode('300');
		$this->assertEquals('300', $r->getHttpStatusCode());
		$r->setHttpStatusCode(400);
		$this->assertEquals('400', $r->getHttpStatusCode());

		try {
			$r->setHttpStatusCode('99');
			$this->fail('Expected Exception was not thrown!');
		} catch (\Exception) {
			$this->assertEquals('400', $r->getHttpStatusCode());
		}

		try {
			$r->setHttpStatusCode('600');
			$this->fail('Expected Exception was not thrown!');
		} catch (\Exception) {
			$this->assertEquals('400', $r->getHttpStatusCode());
		}

		try {
			$r->setHttpStatusCode('not-a-code');
			$this->fail('Expected Exception was not thrown!');
		} catch (\Exception) {
			$this->assertEquals('400', $r->getHttpStatusCode());
		}
	}

	/**
	 * Codes an API framework has to be able to emit: RFC 9110 additions and the
	 * WebDAV/rate-limit range.
	 */
	public function testSetHttpStatusCodeAcceptsTheFullValidRange(): void
	{
		$r = $this->_r;

		foreach (['422', '429', '308', '451', '507', '511', '100', '599'] as $code) {
			$r->setHttpStatusCode($code);
			$this->assertEquals($code, $r->getHttpStatusCode());
		}
	}

	public function testSetRedirectAcceptsPermanentRedirect(): void
	{
		$r = $this->_r;

		$r->setRedirect('/moved', 308);

		$this->assertSame(['location' => '/moved', 'code' => 308], $r->getRedirect());
	}

	public function testSetRedirectRejectsOutOfRangeCode(): void
	{
		$this->expectException(QuioteException::class);

		$this->_r->setRedirect('/moved', 999);
	}

	/**
	 * A subclass may still narrow what its responses are allowed to emit by populating
	 * the httpStatusCodes property; the framework never does.
	 */
	public function testSubclassCanNarrowTheAcceptedCodes(): void
	{
		$narrowed = new class extends WebResponse {
			/** @var ?array<int, string> */
			protected $httpStatusCodes = ['200' => 'OK', '404' => 'Not Found'];
		};

		$this->assertTrue($narrowed->validateHttpStatusCode(404));
		$this->assertFalse($narrowed->validateHttpStatusCode(422));
	}

	public function testNormalizeHttpHeaderName(): void
	{
		$r = $this->_r;

		$this->assertEquals('Location', $r->normalizeHttpHeaderName('lOcation'));
		$this->assertEquals('Location', $r->normalizeHttpHeaderName('Location'));
		$this->assertEquals('Html-Foo-Bar', $r->normalizeHttpHeaderName('hTML-Foo-bAr'));
		$this->assertEquals('Bar-Foo-Baz', $r->normalizeHttpHeaderName('BAR-FOO-BAZ'));

		$this->assertEquals('ETag', $r->normalizeHttpHeaderName('ETAG'));
		$this->assertEquals('ETag', $r->normalizeHttpHeaderName('etag'));
		$this->assertEquals('WWW-Authenticate', $r->normalizeHttpHeaderName('WwW-auThenticate'));
	}

	public function testSetGetHasHttpHeader(): void
	{
		$r = $this->_r;

		$this->assertNull($r->getHttpHeader('Location'));
		$this->assertFalse($r->hasHttpHeader('Location'));

		$r->setHttpHeader('lOCation', 'test1');
		$this->assertTrue($r->hasHttpHeader('lOCAtion'));
		$this->assertTrue($r->hasHttpHeader('Location'));

		$this->assertEquals(['test1'], $r->getHttpHeader('Location'));

		$r->setHttpHeader('location', 'test2');
		$this->assertEquals(['test2'], $r->getHttpHeader('location'));

		$r->setHttpHeader('Location', 'test3', false);
		$this->assertEquals(['test2', 'test3'], $r->getHttpHeader('location'));
	}

	public function testRemoveHttpHeader(): void
	{
		$r = $this->_r;

		$this->assertFalse($r->hasHttpHeader('Location'));
		$this->assertNull($r->removeHttpHeader('Location'));
		$r->setHttpHeader('Location', 'test1');
		$r->setHttpHeader('Location2', 'test2');
		$this->assertTrue($r->hasHttpHeader('Location'));
		$this->assertTrue($r->hasHttpHeader('Location2'));

		$ret = $r->removeHttpHeader('lOcaTiON');
		$this->assertFalse($r->hasHttpHeader('Location'));
		$this->assertTrue($r->hasHttpHeader('Location2'));
		$this->assertEquals(['test1'], $ret);

		$ret = $r->removeHttpHeader('Location2');
		$this->assertFalse($r->hasHttpHeader('Location'));
		$this->assertFalse($r->hasHttpHeader('Location2'));
		$this->assertEquals(['test2'], $ret);
	}

	public function testClearHttpHeaders(): void
	{
		$r = $this->_r;

		$this->assertEquals([], $r->getHttpHeaders());

		$r->setHttpHeader('test 1', 'value 1');
		$r->setHttpHeader('test 2', 'value 2');
		$r->setHttpHeader('test 3', 'value 3');
		$r->setHttpHeader('test 4', 'value 4');
		$this->assertTrue($r->hasHttpHeader('test 1'));
		$this->assertTrue($r->hasHttpHeader('test 2'));
		$this->assertTrue($r->hasHttpHeader('test 3'));
		$this->assertTrue($r->hasHttpHeader('test 4'));

		$r->clearHttpHeaders();

		$this->assertEquals([], $r->getHttpHeaders());
	}

	public function testSetCookie(): void
	{
		$r = $this->_r;

		// Secure-by-default cookie attributes: HttpOnly and SameSite=Lax are on
		// unless explicitly overridden (secure is false here because the test request
		// is not HTTPS).
		$info_ex = [
			'value' => 'value',
			'lifetime' => 0,
			'path' => null,
			'domain' => '',
			'secure' => false,
			'httponly' => true,
			'encode_callback' => 'urlencode',
			'samesite' => 'Lax',
		];
		$r->setCookie('cookieName', 'value');
		$this->assertEquals($info_ex, $r->getCookie('cookieName'));

		$r->setCookie('cookieName', 'value 2', 300, '/foo');
		$info_ex['value'] = 'value 2';
		$info_ex['lifetime'] = 300;
		$info_ex['path'] = '/foo';
		$this->assertEquals($info_ex, $r->getCookie('cookieName'));

		$r->setCookie('cookieName2', 'value 3', 1000, '', 'foo.bar', true);
		$info_ex = [
			'value' => 'value 3',
			'lifetime' => 1000,
			'path' => '',
			'domain' => 'foo.bar',
			'secure' => true,
			'httponly' => true, // secure-by-default (not explicitly overridden)
			'encode_callback' => 'urlencode',
			'samesite' => 'Lax', // secure-by-default (not explicitly overridden)
		];
		$this->assertEquals($info_ex, $r->getCookie('cookieName2'));
	}
	
	public function testCookieEncoding(): void
	{
		$r = $this->_r;
		$r->setCookie('spaceCookie',  'my value');
		$r->setCookie('plusCookie',   'my+value');
		$r->setCookie('customCookie', 'my%01value', null, null, null, null, null, false);
		// Instead of sending headers and relying on SAPI, inspect internal cookies
		$cookies = $r->getCookies();
		$this->assertArrayHasKey('spaceCookie', $cookies);
		$this->assertArrayHasKey('plusCookie', $cookies);
		$this->assertArrayHasKey('customCookie', $cookies);
		// Encoding rules: space -> + (default urlencode), plus -> %2B, raw %01 preserved
		$this->assertEquals('my value', $cookies['spaceCookie']['value']);
		$this->assertEquals('my+value', $cookies['plusCookie']['value']);
		$this->assertEquals('my%01value', $cookies['customCookie']['value']);
	}

	public function testRawCookieEncoding(): void
	{
		$r = $this->_r;
		$r->setParameter('cookie_encode_callback', 'rawurlencode');
		$r->setCookie('spaceCookie',  'my value');
		$r->setCookie('plusCookie',   'my+value');
		$r->setCookie('customCookie', 'my%01value', null, null, null, null, null, false);
		$cookies = $r->getCookies();
		$this->assertEquals('my value', $cookies['spaceCookie']['value']);
		$this->assertEquals('my+value', $cookies['plusCookie']['value']);
		$this->assertEquals('my%01value', $cookies['customCookie']['value']);
	}
}

?>