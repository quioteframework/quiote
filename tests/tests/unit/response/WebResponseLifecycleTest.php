<?php

use Quiote\Exception\InitializationException;
use Quiote\Response\WebResponse;
use Quiote\Testing\UnitTestCase;

/**
 * The parts of WebResponse that are about the response object's own lifecycle
 * rather than about HTTP: initialization, serialization (which has to swap the
 * unserializable Context/OutputType/stream for identifiers), merging, and the
 * between-requests reset a worker relies on.
 */
class WebResponseLifecycleTest extends UnitTestCase
{
	private function makeResponse(): WebResponse
	{
		$response = new WebResponse();
		$response->initialize($this->getContext());
		return $response;
	}

	// ---------------------------------------------------------------
	// Initialization.
	// ---------------------------------------------------------------

	public function testGetContextThrowsBeforeInitialization(): void
	{
		$response = new WebResponse();

		$this->expectException(InitializationException::class);
		$this->expectExceptionMessage('has not been initialized');
		$response->getContext();
	}

	public function testInitializeBindsTheContextAndCookieDefaults(): void
	{
		$response = $this->makeResponse();

		$this->assertSame($this->getContext(), $response->getContext());
		// Secure-by-default cookie attributes, per initialize().
		$this->assertTrue($response->getParameter('cookie_httponly'));
		$this->assertSame('Lax', $response->getParameter('cookie_samesite'));
		$this->assertSame('urlencode', $response->getParameter('cookie_encode_callback'));
	}

	public function testExplicitCookieParametersOverrideTheDefaults(): void
	{
		$response = new WebResponse();
		$response->initialize($this->getContext(), [
			'cookie_httponly' => false,
			'cookie_samesite' => 'Strict',
			'cookie_secure'   => true,
		]);

		$this->assertFalse($response->getParameter('cookie_httponly'));
		$this->assertSame('Strict', $response->getParameter('cookie_samesite'));
		$this->assertTrue($response->getParameter('cookie_secure'));
	}

	// ---------------------------------------------------------------
	// Content.
	// ---------------------------------------------------------------

	public function testHasContentTreatsTheEmptyStringAsNoContent(): void
	{
		$response = $this->makeResponse();

		$this->assertFalse($response->hasContent());
		$response->setContent('');
		$this->assertFalse($response->hasContent());
		$response->setContent('0');
		$this->assertTrue($response->hasContent());
	}

	public function testContentSizeCountsBytesForStringsAndStreams(): void
	{
		$response = $this->makeResponse();
		$response->setContent('12345');
		$this->assertSame(5, $response->getContentSize());

		$stream = fopen('php://temp', 'r+');
		$this->assertNotFalse($stream);
		fwrite($stream, 'abcdefg');
		rewind($stream);
		$response->setContent($stream);
		$this->assertSame(7, $response->getContentSize());
		fclose($stream);
	}

	public function testNonStringableContentSizeIsZeroRatherThanAnError(): void
	{
		$response = $this->makeResponse();
		// Views may hand the response an array (e.g. for a JSON renderer).
		$response->setContent(['a' => 1]);

		$this->assertSame(0, $response->getContentSize());
	}

	public function testContentIsNotMutableForStreamsOrRedirects(): void
	{
		$response = $this->makeResponse();
		$this->assertTrue($response->isContentMutable());

		$stream = fopen('php://temp', 'r+');
		$this->assertNotFalse($stream);
		$response->setContent($stream);
		$this->assertFalse($response->isContentMutable());
		fclose($stream);

		$plain = $this->makeResponse();
		$plain->setRedirect('/elsewhere');
		$this->assertFalse($plain->isContentMutable());
	}

	// ---------------------------------------------------------------
	// Merging.
	// ---------------------------------------------------------------

	public function testMergeImportsHeadersCookiesRedirectAndAttributes(): void
	{
		$target = $this->makeResponse();
		$source = $this->makeResponse();

		$source->setHttpHeader('X-From-Source', 'yes');
		$source->setCookie('src', 'value');
		$source->setRedirect('/from-source', 301);
		$source->setAttribute('shared', 'source');

		$target->merge($source);

		$this->assertSame(['yes'], $target->getHttpHeader('X-From-Source'));
		$this->assertTrue($target->hasCookie('src'));
		$this->assertSame(['location' => '/from-source', 'code' => 301], $target->getRedirect());
		$this->assertSame('source', $target->getAttribute('shared'));
	}

	public function testMergeNeverOverwritesWhatIsAlreadySet(): void
	{
		$target = $this->makeResponse();
		$source = $this->makeResponse();

		$target->setHttpHeader('X-Both', 'target');
		$target->setCookie('both', 'target');
		$target->setRedirect('/target', 302);
		$target->setAttribute('shared', 'target');

		$source->setHttpHeader('X-Both', 'source');
		$source->setCookie('both', 'source');
		$source->setRedirect('/source', 301);
		$source->setAttribute('shared', 'source');

		$target->merge($source);

		$this->assertSame(['target'], $target->getHttpHeader('X-Both'));
		$cookie = $target->getCookie('both');
		$this->assertNotNull($cookie);
		$this->assertSame('target', $cookie['value']);
		$this->assertSame(['location' => '/target', 'code' => 302], $target->getRedirect());
		$this->assertSame('target', $target->getAttribute('shared'));
	}

	public function testMergeCombinesArrayValuedAttributes(): void
	{
		$target = $this->makeResponse();
		$source = $this->makeResponse();

		$target->setAttribute('list', ['from-target']);
		$source->setAttribute('list', ['from-source']);

		$target->merge($source);

		$this->assertSame(['from-source', 'from-target'], $target->getAttribute('list'));
	}

	// ---------------------------------------------------------------
	// Serialization.
	// ---------------------------------------------------------------

	public function testSerializationRestoresContextHeadersAndContent(): void
	{
		$response = $this->makeResponse();
		$response->setContent('body');
		$response->setHttpHeader('X-Kept', 'yes');
		$response->setHttpStatusCode(201);
		$response->setAttribute('kept', 'yes');
		$response->setOutputType($this->getContext()->getController()->getOutputType('html'));

		/** @var WebResponse $restored */
		$restored = unserialize(serialize($response));

		$this->assertSame($this->getContext(), $restored->getContext());
		$this->assertSame('body', $restored->getContent());
		$this->assertSame(['yes'], $restored->getHttpHeader('X-Kept'));
		$this->assertSame('201', $restored->getHttpStatusCode());
		$this->assertSame('yes', $restored->getAttribute('kept'));
		$this->assertSame('html', $restored->getOutputType()?->getName());
	}

	public function testSerializationReopensStreamContentFromItsMetadata(): void
	{
		$path = tempnam(sys_get_temp_dir(), 'quiote-response-');
		$this->assertNotFalse($path);
		file_put_contents($path, 'streamed body');

		try {
			$response = $this->makeResponse();
			$stream = fopen($path, 'rb');
			$this->assertNotFalse($stream);
			$response->setContent($stream);

			/** @var WebResponse $restored */
			$restored = unserialize(serialize($response));

			$restoredStream = $restored->getContent();
			$this->assertIsResource($restoredStream);
			$this->assertSame('streamed body', stream_get_contents($restoredStream));
			fclose($restoredStream);
			fclose($stream);
		} finally {
			unlink($path);
		}
	}

	public function testSerializationWithoutAnOutputTypeOrStreamRoundTrips(): void
	{
		$response = $this->makeResponse();
		$response->setContent('plain');

		/** @var WebResponse $restored */
		$restored = unserialize(serialize($response));

		$this->assertSame('plain', $restored->getContent());
		$this->assertNull($restored->getOutputType());
	}

	// ---------------------------------------------------------------
	// Reset (worker mode).
	// ---------------------------------------------------------------

	public function testResetClearsEverythingARequestCanPutOnTheResponse(): void
	{
		$response = $this->makeResponse();
		$response->setContent('previous request body');
		$response->setHttpStatusCode(404);
		$response->setHttpHeader('X-Leak', 'yes');
		$response->setCookie('leak', 'yes');
		$response->setRedirect('/leak');
		$response->setAttribute('leak', 'yes');
		$response->setOutputType($this->getContext()->getController()->getOutputType('html'));

		$response->reset();

		$this->assertNull($response->getContent());
		$this->assertFalse($response->hasContent());
		$this->assertSame('200', $response->getHttpStatusCode());
		$this->assertSame([], $response->getHttpHeaders());
		$this->assertSame([], $response->getCookies());
		$this->assertFalse($response->hasRedirect());
		$this->assertFalse($response->hasAttribute('leak'));
		$this->assertNull($response->getOutputType());
	}

	public function testResetKeepsTheContextSoAReusedResponseStaysUsable(): void
	{
		$response = $this->makeResponse();

		$response->reset();

		// The Context is application-scoped, and a pooled response is not
		// re-initialize()d before the next request picks it up.
		$this->assertSame($this->getContext(), $response->getContext());
	}

	public function testClearLeavesTheResponseReusableWithoutTouchingAttributes(): void
	{
		$response = $this->makeResponse();
		$response->setContent('body');
		$response->setHttpStatusCode(500);
		$response->setHttpHeader('X-Gone', 'yes');
		$response->setCookie('gone', 'yes');
		$response->setRedirect('/gone');
		$response->setAttribute('kept', 'yes');

		$response->clear();

		$this->assertNull($response->getContent());
		$this->assertSame('200', $response->getHttpStatusCode());
		$this->assertSame([], $response->getHttpHeaders());
		$this->assertSame([], $response->getCookies());
		$this->assertFalse($response->hasRedirect());
		// clear() is about response output only; attributes survive it.
		$this->assertSame('yes', $response->getAttribute('kept'));
	}
}
