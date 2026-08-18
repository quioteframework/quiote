<?php
namespace Quiote\Response;

use Quiote\Context;
use Quiote\Config\Config;
use Quiote\Controller\OutputType;
use Quiote\Exception\InitializationException;
use Quiote\Exception\QuioteException;
use Quiote\Request\WebRequest;
use Quiote\Util\AttributeHolder;
use Symfony\Contracts\Service\ResetInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Http\SimpleStream;

/**
 * WebResponse handles the HTTP response: status code, headers, cookies,
 * redirects and the content sent back to the client.
 * @since      1.0.0
 * @version    1.0.0
 */
class WebResponse extends AttributeHolder implements ResetInterface, WebResponseInterface
{


	/**
	 * Per-protocol status tables kept as protected API for application subclasses that read
	 * them. The framework consults neither: {@see validateHttpStatusCode()} asks
	 * {@see \Quiote\Http\HttpStatus}, and the status line is built by the runtime emitter
	 * from the PSR-7 response.
	 * @deprecated 3.2.0 Use {@see \Quiote\Http\HttpStatus} instead.
	 * @var        array<int, string> An array of all HTTP 1.0 status codes and their message.
	 */
	protected static $http10StatusCodes = [
		'200' => "HTTP/1.0 200 OK",
		'201' => "HTTP/1.0 201 Created",
		'202' => "HTTP/1.0 202 Accepted",
		'204' => "HTTP/1.0 204 No Content",
		'205' => "HTTP/1.0 205 Reset Content",
		'206' => "HTTP/1.0 206 Partial Content",
		'300' => "HTTP/1.0 300 Multiple Choices",
		'301' => "HTTP/1.0 301 Moved Permanently",
		'302' => "HTTP/1.0 302 Found",
		'304' => "HTTP/1.0 304 Not Modified",
		'400' => "HTTP/1.0 400 Bad Request",
		'401' => "HTTP/1.0 401 Unauthorized",
		'402' => "HTTP/1.0 402 Payment Required",
		'403' => "HTTP/1.0 403 Forbidden",
		'404' => "HTTP/1.0 404 Not Found",
		'405' => "HTTP/1.0 405 Method Not Allowed",
		'406' => "HTTP/1.0 406 Not Acceptable",
		'407' => "HTTP/1.0 407 Proxy Authentication Required",
		'408' => "HTTP/1.0 408 Request Timeout",
		'409' => "HTTP/1.0 409 Conflict",
		'410' => "HTTP/1.0 410 Gone",
		'411' => "HTTP/1.0 411 Length Required",
		'412' => "HTTP/1.0 412 Precondition Failed",
		'413' => "HTTP/1.0 413 Request Entity Too Large",
		'414' => "HTTP/1.0 414 Request-URI Too Long",
		'415' => "HTTP/1.0 415 Unsupported Media Type",
		'416' => "HTTP/1.0 416 Requested Range Not Satisfiable",
		'417' => "HTTP/1.0 417 Expectation Failed",
		'500' => "HTTP/1.0 500 Internal Server Error",
		'501' => "HTTP/1.0 501 Not Implemented",
		'502' => "HTTP/1.0 502 Bad Gateway",
		'503' => "HTTP/1.0 503 Service Unavailable",
		'504' => "HTTP/1.0 504 Gateway Timeout",
		'505' => "HTTP/1.0 505 HTTP Version Not Supported",
	];

	/**
	 * @deprecated 3.2.0 Use {@see \Quiote\Http\HttpStatus} instead.
	 * @var        array<int, string> An array of all HTTP 1.1 status codes and their message.
	 */
	protected static $http11StatusCodes = [
		'100' => "HTTP/1.1 100 Continue",
		'101' => "HTTP/1.1 101 Switching Protocols",
		'200' => "HTTP/1.1 200 OK",
		'201' => "HTTP/1.1 201 Created",
		'202' => "HTTP/1.1 202 Accepted",
		'203' => "HTTP/1.1 203 Non-Authoritative Information",
		'204' => "HTTP/1.1 204 No Content",
		'205' => "HTTP/1.1 205 Reset Content",
		'206' => "HTTP/1.1 206 Partial Content",
		'300' => "HTTP/1.1 300 Multiple Choices",
		'301' => "HTTP/1.1 301 Moved Permanently",
		'302' => "HTTP/1.1 302 Found",
		'303' => "HTTP/1.1 303 See Other",
		'304' => "HTTP/1.1 304 Not Modified",
		'305' => "HTTP/1.1 305 Use Proxy",
		'307' => "HTTP/1.1 307 Temporary Redirect",
		'400' => "HTTP/1.1 400 Bad Request",
		'401' => "HTTP/1.1 401 Unauthorized",
		'402' => "HTTP/1.1 402 Payment Required",
		'403' => "HTTP/1.1 403 Forbidden",
		'404' => "HTTP/1.1 404 Not Found",
		'405' => "HTTP/1.1 405 Method Not Allowed",
		'406' => "HTTP/1.1 406 Not Acceptable",
		'407' => "HTTP/1.1 407 Proxy Authentication Required",
		'408' => "HTTP/1.1 408 Request Timeout",
		'409' => "HTTP/1.1 409 Conflict",
		'410' => "HTTP/1.1 410 Gone",
		'411' => "HTTP/1.1 411 Length Required",
		'412' => "HTTP/1.1 412 Precondition Failed",
		'413' => "HTTP/1.1 413 Request Entity Too Large",
		'414' => "HTTP/1.1 414 Request-URI Too Long",
		'415' => "HTTP/1.1 415 Unsupported Media Type",
		'416' => "HTTP/1.1 416 Requested Range Not Satisfiable",
		'417' => "HTTP/1.1 417 Expectation Failed",
		'500' => "HTTP/1.1 500 Internal Server Error",
		'501' => "HTTP/1.1 501 Not Implemented",
		'502' => "HTTP/1.1 502 Bad Gateway",
		'503' => "HTTP/1.1 503 Service Unavailable",
		'504' => "HTTP/1.1 504 Gateway Timeout",
		'505' => "HTTP/1.1 505 HTTP Version Not Supported",
	];

	/**
	 * An optional NARROWING whitelist of acceptable status codes.
	 *
	 * Null -- the default -- means "anything {@see \Quiote\Http\HttpStatus} considers a
	 * valid status code", which is the whole 100-599 range. An application that genuinely
	 * wants to constrain what its responses may emit can populate this in a subclass and
	 * {@see validateHttpStatusCode()} will honour it. The framework never sets it.
	 * @var        ?array<int, string> The array with the HTTP status codes to be used here.
	 */
	protected $httpStatusCodes = null;

	/**
	 * @var        string The HTTP status code to send for the response.
	 */
	protected $httpStatusCode = '200';

	/**
	 * @var        array<string, list<string>> The HTTP headers scheduled to be sent with the response.
	 */
	protected $httpHeaders = [];

	/**
	 * @var        array<string, array{value: mixed, lifetime: int|string|null, path: string|null, domain: string|null, secure: bool, httponly: bool, encode_callback: callable|false, samesite: string|null}> The Cookies scheduled to be sent with the response.
	 */
	protected $cookies = [];

	/**
	 * @var        ?array{location: string, code: int|string} An array of redirect information, or null if no redirect.
	 */
	protected $redirect = null;

	/** @var ?Context */
	protected $context = null;
	/** @var mixed */
	protected $content = null;
	/** @var ?OutputType */
	protected $outputType = null;

	/** @var ?ResponseInterface PSR-7 response attached for forwarding */
	protected ?ResponseInterface $psrResponse = null;

	/**
	 * @var ?ResponseInterface The response staged by {@see send()}, awaiting emission
	 *      by the runtime's emitter. Request-scoped: cleared by clear()/reset() so a
	 *      staged response cannot be emitted again for the next request in a worker.
	 */
	protected ?ResponseInterface $stagedResponse = null;

	/**
	 * @var        ?string Context name, stand-in for the Context instance while serialized.
	 */
	protected final $contextName;

	/**
	 * @var        ?string Output type name, stand-in for the OutputType instance while serialized.
	 */
	protected final $outputTypeName;

	/**
	 * @var        ?array<string, mixed> Stream metadata, stand-in for stream content while serialized.
	 */
	protected final $contentStreamMeta;

	/**
	 * Pre-serialization callback.
	 * Heavy object references (the Context, the OutputType) and the content
	 * stream cannot be serialized, so record the identifiers needed to look
	 * them back up in __wakeup() and leave the objects themselves out.
	 * @return     list<string>
	 * @since      1.0.0
	 */
	public function __sleep()
	{
		$vars = get_object_vars($this);

		$this->contextName = $this->context?->getName();
		unset($vars['context']);

		if($this->outputType) {
			$this->outputTypeName = $this->outputType->getName();
			unset($vars['outputType']);
		}

		if(is_resource($this->content)) {
			$this->contentStreamMeta = stream_get_meta_data($this->content);
			unset($vars['content']);
		}

		// The PSR-7 responses are request-scoped and not necessarily serializable.
		unset($vars['psrResponse'], $vars['stagedResponse']);

		return array_keys($vars);
	}

	/**
	 * Post-unserialization callback.
	 * Restores the Context, the OutputType and the content stream from the
	 * identifiers recorded by __sleep().
	 * @return     void
	 * @since      1.0.0
	 */
	public function __wakeup()
	{
		$this->context = Context::getInstance($this->contextName);
		unset($this->contextName);

		if(isset($this->outputTypeName)) {
			$this->outputType = $this->context->getContainer()->get(\Quiote\Controller\Controller::class)->getOutputType($this->outputTypeName);
			unset($this->outputTypeName);
		}

		if(isset($this->contentStreamMeta)) {
			// contrary to what the documentation says, stream_get_meta_data() will not
			// return the list of filters attached to the stream, so those cannot be restored.
			$uri = $this->contentStreamMeta['uri'] ?? null;
			$mode = $this->contentStreamMeta['mode'] ?? null;
			$this->content = null;
			if(is_string($uri) && is_string($mode)) {
				$stream = fopen($uri, $mode);
				if($stream !== false) {
					$this->content = $stream;
				}
			}
			unset($this->contentStreamMeta);
		}
	}

	/**
	 * Retrieve the Context instance this Response object belongs to.
	 * @return     Context An Context instance.
	 * @throws     InitializationException If this Response has not been initialized yet.
	 * @since      1.0.0
	 */
	public final function getContext()
	{
		if ($this->context === null) {
			throw new InitializationException(sprintf('%s has not been initialized; call initialize() first.', static::class));
		}
		return $this->context;
	}

	/**
	 * Retrieve the content set for this Response.
	 * @return     mixed The content set in this Response.
	 * @since      1.0.0
	 */
	public function getContent()
	{
		return $this->content;
	}

	/**
	 * Attach a PSR-7 response instance for forwarding.
	 */
	public function setPsrResponse(?ResponseInterface $psr): void
	{
		$this->psrResponse = $psr;
		if($psr !== null) {
			try {
				$body = (string) $psr->getBody();
				if($body !== '') {
					$this->setContent($body);
				}
			} catch(\Throwable $e) {
				// The attached response's body never made it across, so this response keeps
				// whatever content it already had -- possibly none.
				\Quiote\Logging\Log::for($this)->warning(
					'[WebResponse] could not read the attached PSR-7 body: ' . $e->getMessage()
				);
			}
		}
	}

	/**
	 * Returns the attached PSR-7 response, or null when none was attached.
	 *
	 * Not a snapshot of this response: it is the instance handed to
	 * {@see setPsrResponse()}, replaced in place as status codes, headers and
	 * cookies set on this response are mirrored onto it. It is null until a
	 * response is attached, and again after {@see reset()}.
	 */
	public function getPsrResponse(): ?ResponseInterface
	{
		return $this->psrResponse;
	}

	/**
	 * Retrieve the size (in bytes) of the content set for this Response.
	 * @return int|false The content size in bytes, or false if it could not be determined.
	 */
	public function getContentSize()
	{
		if (is_resource($this->content)) {
			if (($stat = fstat($this->content)) !== false) {
				return $stat['size'];
			} else {
				return false;
			}
		} else {
			return strlen(self::toStringOrEmpty($this->content));
		}
	}

	/**
	 * Set the content for this Response.
	 * @param      mixed $content The content to be sent in this Response.
	 * @return     void
	 */
	public function setContent($content)
	{
		$this->content = $content;
	}

	/**
	 * Prepend content to the existing content for this Response.
	 * @param      mixed $content The content to be prepended to this Response.
	 * @return     void
	 */
	public function prependContent($content)
	{
		$this->setContent(self::toStringOrEmpty($content) . self::toStringOrEmpty($this->getContent()));
	}

	/**
	 * Append content to the existing content for this Response.
	 * @param      mixed $content The content to be appended to this Response.
	 * @return     void
	 */
	public function appendContent($content)
	{
		$this->setContent(self::toStringOrEmpty($this->getContent()) . self::toStringOrEmpty($content));
	}

	/**
	 * Clear the content for this Response.
	 * @return     void
	 */
	public function clearContent()
	{
		$this->content = null;
	}

	/**
	 * Get the Output Type to use with this response.
	 * @return     ?OutputType The Output Type instance associated with, or null if none is set.
	 */
	public function getOutputType()
	{
		return $this->outputType;
	}

	/**
	 * Set the Output Type to use with this response.
	 * @return     void
	 */
	public function setOutputType(OutputType $outputType)
	{
		$this->outputType = $outputType;
	}

	/**
	 * Clear the Output Type to use with this response.
	 * @return     void
	 */
	public function clearOutputType()
	{
		$this->outputType = null;
	}

	/**
	 * Reset response state for worker compatibility: everything a request can
	 * put on the response has to go, or request N's body/headers/cookies would
	 * bleed into request N+1. The Context is deliberately kept -- it is
	 * application-scoped, not request-scoped, and a reused response instance is
	 * not re-initialize()d before the next request.
	 * @since      1.0.0
	 */
	#[\Override]
    public function reset(): void
	{
		$this->httpStatusCode = '200';
		$this->httpHeaders = [];
		$this->cookies = [];
		$this->redirect = null;
		$this->httpStatusCodes = null;

		$this->content = null;
		$this->outputType = null;
		$this->psrResponse = null;
		// Request-scoped: a response staged by send() must never be emitted again
		// for the next request this worker serves.
		$this->stagedResponse = null;

		// Serialization scratch space; stale values would confuse __wakeup().
		$this->contextName = null;
		$this->outputTypeName = null;
		$this->contentStreamMeta = null;

		$this->clearAttributes();
		$this->clearParameters();
	}

	/**
	 * Initialize this Response.
	 * @param      Context $context An Context instance.
	 * @param      array<string, mixed> $parameters An array of initialization parameters.
	 * @return     void
	 * @since      1.0.0
	 */
	public function initialize(Context $context, array $parameters = [])
	{
		$this->context = $context;
		$this->setParameters($parameters);

		/** @var ?WebRequest */
		$request = null;
		try {
			$request = $context->getContainer()->get(\Quiote\Request\WebRequest::class);
		} catch (\Exception $e) {
			\Quiote\Logging\Log::for($this)->debug('WebResponse::initialize - request not available during bootstrap: ' . $e->getMessage());
			$request = null;
		}

		// Secure-by-default cookie attributes. Unless an application explicitly
		// overrides them, cookies set through this response are:
		//   - Secure   when the request is HTTPS (so they are never sent in clear),
		//   - HttpOnly (not readable by client-side script — mitigates XSS theft),
		//   - SameSite=Lax (not sent on cross-site subrequests — CSRF defense-in-depth),
		//   - URL-encoded (cookie_encode_callback) so values cannot inject attributes.
		// An app that genuinely needs a JS-readable or cross-site cookie must opt out
		// explicitly per call (e.g. setCookie(..., $httponly = false)).
		if (!array_key_exists('cookie_secure', $parameters) || $parameters['cookie_secure'] === null) {
			$parameters['cookie_secure'] = $request !== null && self::requestIsHttps($request);
		}

		$this->setParameters([
			'cookie_lifetime' => $parameters['cookie_lifetime'] ?? 0,
			'cookie_path'     => $parameters['cookie_path'] ?? null,
			'cookie_domain'   => $parameters['cookie_domain'] ?? "",
			'cookie_secure'   => $parameters['cookie_secure'],
			'cookie_httponly' => $parameters['cookie_httponly'] ?? true,
			'cookie_encode_callback' => $parameters['cookie_encode_callback'] ?? 'urlencode',
			'cookie_samesite' => $parameters['cookie_samesite'] ?? 'Lax',
		]);

		// No status whitelist is installed: validity is a property of the status code,
		// not of the protocol version carrying it, so validateHttpStatusCode() answers
		// from Quiote\Http\HttpStatus for every request regardless of protocol.
	}

	/**
	 * Get the HTTP protocol string from a request object.
	 * Supports both WebRequest::getProtocol() and PSR-7 getProtocolVersion().
	 * Protected API for application subclasses; the framework itself has no caller.
	 * @param      mixed $request A request object or null.
	 * @return     string The HTTP protocol (e.g., "HTTP/1.1").
	 */
	protected function getRequestProtocol($request): string
	{
		if ($request instanceof WebRequest) {
			return $request->getProtocol() ?? 'HTTP/1.1';
		} elseif ($request instanceof \Psr\Http\Message\RequestInterface) {
			return 'HTTP/' . $request->getProtocolVersion();
		}
		return 'HTTP/1.1';
	}

	/**
	 * Stage this response for emission.
	 *
	 * Deliberately does no transport of its own. Transport is the one thing that
	 * genuinely differs between hosts -- header()/echo reaches the client under
	 * php-fpm and FrankenPHP, but under RoadRunner header() is a no-op and echo
	 * lands on the protocol relay -- so it belongs to the runtime's
	 * {@see \Quiote\Runtime\Emitter\ResponseEmitterInterface} and nowhere else.
	 * This method materializes the response ({@see toPsrResponse()}) and hands it
	 * to the pipeline, which returns it to {@see \Quiote\Runtime\Worker\WorkerLoop}
	 * for that one emitter to send. One owner of emission, identical on every
	 * runtime.
	 *
	 * The visible change against the pre-3.1.2 behaviour is timing, not content:
	 * the bytes are no longer flushed at the call site, they go out when the
	 * pipeline unwinds. Nothing is lost -- {@see \Quiote\Middleware\DispatchMiddleware}
	 * prefers a staged response over the one it would otherwise build.
	 *
	 * @param      OutputType $outputType An optional Output Type object with information
	 *                             the response can use to send additional data,
	 *                             such as HTTP headers
	 * @return     void
	 * @since      1.0.0
	 */
	public function send(?OutputType $outputType = null)
	{
		$this->stagedResponse = $this->toPsrResponse($outputType);

		// Keep the separately-attached "PSR response for forwarding" in step. Status,
		// headers and cookies already mirror onto it as they are set; the body only
		// ever did so from sendContent(), so reflect it here to preserve that.
		if($this->psrResponse !== null) {
			try {
				$this->psrResponse = $this->psrResponse->withBody($this->stagedResponse->getBody());
			} catch(\Throwable $e) {
				// Only the separately-attached forwarding response is out of step; the staged
				// response the runtime emits already carries the body.
				\Quiote\Logging\Log::for($this)->warning(
					'[WebResponse] could not mirror the staged body onto the attached PSR-7 response: '
					. $e->getMessage()
				);
			}
		}
	}

	/**
	 * Materialize this response as PSR-7: status, prepared headers, queued
	 * cookies and body, with no side effects on any output channel.
	 *
	 * This is the conversion every runtime shares -- what used to be spread across
	 * send()/sendHttpResponseHeaders()/sendContent() as a pile of header() and echo
	 * calls that only worked under a SAPI.
	 *
	 * @param      ?OutputType $outputType Output type whose http_headers to fold in;
	 *                         defaults to this response's own.
	 * @return     ResponseInterface
	 * @throws     QuioteException If a relative redirect is set with no initialized Context.
	 * @since      3.1.2
	 */
	public function toPsrResponse(?OutputType $outputType = null): ResponseInterface
	{
		if($this->redirect) {
			$this->applyRedirectHeaders();
		}

		$this->prepareHttpResponseHeaders($outputType);

		return (new PsrResponseBuilder())->build(
			(int) $this->httpStatusCode,
			$this->httpHeaders,
			$this->buildSetCookieHeaders(),
			$this->content,
			!$this->redirect || (bool) $this->getParameter('send_redirect_content', false),
			$this->sendfileHeaderName(),
		);
	}

	/**
	 * Turn a queued redirect into a Location header and status code.
	 *
	 * A relative location is made absolute first: a path-rooted one against the current
	 * request's scheme and authority, anything else against the routing base href. Both need
	 * an initialized Context, so a relative redirect without one is refused rather than
	 * emitted as a Location the client cannot resolve.
	 *
	 * @throws     QuioteException If a relative redirect is set with no initialized Context.
	 */
	private function applyRedirectHeaders(): void
	{
		$redirect = $this->redirect;
		if($redirect === null) {
			return;
		}

		$location = $redirect['location'];
		if(!preg_match('#^[^:]+://#', (string) $location)) {
			if($this->context === null) {
				throw new QuioteException('WebResponse::toPsrResponse - cannot build a relative redirect location without an initialized Context');
			}
			if(isset($location[0]) && $location[0] == '/') {
				$rq = $this->context->getContainer()->get(\Quiote\Request\WebRequest::class);
				$location = $rq->getUrlScheme() . '://' . $rq->getUrlAuthority() . $location;
			} else {
				$location = $this->context->getContainer()->get(\Quiote\Routing\Routing::class)->getBaseHref() . $location;
			}
		}

		$this->setHttpHeader('Location', $location);
		$this->setHttpStatusCode($redirect['code']);
		if($this->getParameter('send_content_length', true) && !$this->hasHttpHeader('Content-Length') && !$this->getParameter('send_redirect_content', false)) {
			$this->setHttpHeader('Content-Length', 0);
		}
	}

	/**
	 * The header name a file-backed body's path should be handed to the front-end server
	 * through, or null when this response does not use the sendfile contract.
	 */
	private function sendfileHeaderName(): ?string
	{
		if(!$this->getParameter('use_sendfile_header', false)) {
			return null;
		}
		$name = $this->getParameter('sendfile_header_name', 'X-Sendfile');

		return is_string($name) && $name !== '' ? $name : 'X-Sendfile';
	}

	/**
	 * Whether {@see send()} has staged a response awaiting emission.
	 * @since      3.1.2
	 */
	public function hasStagedResponse(): bool
	{
		return $this->stagedResponse !== null;
	}

	/**
	 * The response staged by {@see send()}, or null if send() was never called.
	 * @since      3.1.2
	 */
	public function getStagedResponse(): ?ResponseInterface
	{
		return $this->stagedResponse;
	}

	/**
	 * Send the content for this response.
	 * @deprecated Call send() instead; this no longer echoes, because emission
	 *             belongs to the runtime's emitter. Kept so existing callers still
	 *             get their content to the client rather than silently losing it.
	 * @return     void
	 * @since      1.0.0
	 */
	public function sendContent()
	{
		$this->send();
	}

	/**
	 * Clear all response data.
	 * @return     void
	 * @since      1.0.0
	 */
	public function clear()
	{
		$this->clearContent();
		$this->httpStatusCode = '200';
		$this->httpHeaders = [];
		$this->cookies = [];
		$this->redirect = null;
		$this->stagedResponse = null;
	}

	/**
	 * Check whether or not some content is set.
	 * @return     bool If any content is set, false otherwise.
	 * @since      1.0.0
	 */
    public function hasContent()
	{
		return $this->content !== null && $this->content !== '';
	}

	/**
	 * Set the content type for the response.
	 * @param      string $type A content type.
	 * @return     void
	 * @since      1.0.0
	 */
	public function setContentType($type)
	{
		$this->setHttpHeader('Content-Type', $type);
	}

	/**
	 * Retrieve the content type set for the response.
	 * @return     ?string A content type, or null if none is set.
	 * @since      1.0.0
	 */
	public function getContentType()
	{
		$retval = $this->getHttpHeader('Content-Type');
		if(is_array($retval) && count($retval)) {
			return $retval[0];
		} else {
			return null;
		}
	}

	/**
	 * Import response metadata (attributes, headers, cookies, redirect) from
	 * another response. Anything already set on this response wins; array-valued
	 * attributes are merged with this response's entries taking precedence.
	 * @param      WebResponse $otherResponse The other response to import information from.
	 * @return     void
	 * @since      1.0.0
	 */
	public function merge($otherResponse)
	{
		foreach($otherResponse->getAttributeNamespaces() as $namespace) {
			foreach($otherResponse->getAttributes($namespace) as $name => $value) {
				if(!$this->hasAttribute($name, $namespace)) {
					$this->setAttribute($name, $value, $namespace);
				} elseif(is_array($value)) {
					$thisAttribute =& $this->getAttribute($name, $namespace);
					if(is_array($thisAttribute)) {
						$thisAttribute = array_merge($value, $thisAttribute);
					}
				}
			}
		}

		foreach($otherResponse->getHttpHeaders() as $name => $value) {
			if(!$this->hasHttpHeader($name)) {
				$this->setHttpHeader($name, $value);
			}
		}
		foreach($otherResponse->getCookies() as $name => $cookie) {
			if(!$this->hasCookie($name)) {
				$this->setCookie($name, $cookie['value'], $cookie['lifetime'], $cookie['path'], $cookie['domain'], $cookie['secure'], $cookie['httponly'], $cookie['encode_callback']);
			}
		}
		$redirect = $otherResponse->getRedirect();
		if($redirect !== null && !$this->hasRedirect()) {
			$this->setRedirect($redirect['location'], $redirect['code']);
		}
	}

	/**
	 * Determine whether the content in the response may be modified by appending
	 * or prepending data using string operations. Typically false for streams
	 * or responses where the content is not a string (e.g. an array).
	 * @return     bool If the content can be treated as / changed like a string.
	 */
	public function isContentMutable()
	{
		return !$this->hasRedirect() && !is_resource($this->content);
	}

	/**
	 * Check if the given HTTP status code is valid.
	 *
	 * Delegates to {@see \Quiote\Http\HttpStatus::isValid()} -- the full 100-599 range --
	 * unless a subclass has populated {@see $httpStatusCodes} to narrow it further.
	 * @param      string|int $code A numeric HTTP status code.
	 * @return     bool True, if the code is valid, or false otherwise.
	 * @since      1.0.0
	 */
	public function validateHttpStatusCode($code)
	{
		if($this->httpStatusCodes !== null) {
			return isset($this->httpStatusCodes[(string)$code]);
		}
		return \Quiote\Http\HttpStatus::isValid(is_int($code) ? $code : (string)$code);
	}

	/**
	 * Sets a HTTP status code for the response.
	 * @param      string|int $code A numeric HTTP status code.
	 * @return     void
	 * @since      1.0.0
	 */
	public function setHttpStatusCode(string|int $code)
	{
		$code = (string)$code;
		if($this->validateHttpStatusCode($code)) {
			$this->httpStatusCode = $code;
			if($this->psrResponse !== null) {
				try {
					$this->psrResponse = $this->psrResponse->withStatus((int)$code);
				} catch(\Throwable $e) {
					// This response holds the new status either way; only the attached PSR-7
					// response still reports the old one.
					\Quiote\Logging\Log::for($this)->warning(
						'[WebResponse] could not mirror status ' . $code
						. ' onto the attached PSR-7 response: ' . $e->getMessage()
					);
				}
			}
		} else {
			throw new QuioteException(sprintf(
				'Invalid HTTP status code: %s (expected %d-%d)',
				$code,
				\Quiote\Http\HttpStatus::MIN,
				\Quiote\Http\HttpStatus::MAX,
			));
		}
	}

	/**
	 * Gets the HTTP status code set for the response.
	 * @return     string A numeric HTTP status code between 100 and 505, or null
	 *                    if no status code has been set.
	 * @since      1.0.0
	 */
	public function getHttpStatusCode()
	{
		return $this->httpStatusCode;
	}

	/**
	 * Normalizes a HTTP header names
	 * @param      string $name A HTTP header name
	 * @return     string A normalized HTTP header name
	 * @since      1.0.0
	 */
	public function normalizeHttpHeaderName($name)
	{
		// HTTP header names form a small closed set; the raw -> normalized mapping
		// is pure, so memoize it for the process lifetime rather than re-running the
		// strtolower/ucwords/str_replace pipeline on every header access.
		static $cache = [];
		$key = (string) $name;
		if(isset($cache[$key])) {
			return $cache[$key];
		}
		$lower = strtolower($key);
		if($lower === "etag") {
			$normalized = "ETag";
		} elseif($lower === "www-authenticate") {
			$normalized = "WWW-Authenticate";
		} else {
			$normalized = str_replace(' ', '-', ucwords(str_replace('-', ' ', $lower)));
		}
		return $cache[$key] = $normalized;
	}

	/**
	 * Retrieve the HTTP header values set for the response.
	 * @param      string $name A HTTP header field name.
	 * @return     ?list<string> All values set for that header, or null if no headers set
	 * @since      1.0.0
	 */
	public function getHttpHeader($name)
	{
		$name = $this->normalizeHttpHeaderName($name);
		$retval = null;
		if(isset($this->httpHeaders[$name])) {
			$retval = $this->httpHeaders[$name];
		}
		return $retval;
	}

	/**
	 * Retrieve the HTTP headers set for the response.
	 * @return     array<string, list<string>> An associative array of HTTP header names and values.
	 * @since      1.0.0
	 */
	public function getHttpHeaders()
	{
		return $this->httpHeaders;
	}

	/**
	 * Normalize a value to its string form for output (HTTP header value, cookie
	 * value, response body). Scalars and Stringables convert directly; anything
	 * else (array, non-Stringable object) yields an empty string rather than a
	 * type error.
	 */
	private static function toStringOrEmpty(mixed $value): string
	{
		if (is_scalar($value) || $value instanceof \Stringable) {
			return (string) $value;
		}
		return '';
	}

	/**
	 * Check if an HTTP header has been set for the response.
	 * @param      string $name A HTTP header field name.
	 * @return     bool true if the header exists, false otherwise.
	 * @since      1.0.0
	 */
	public function hasHttpHeader($name)
	{
		$name = $this->normalizeHttpHeaderName($name);
		$retval = false;
		if(isset($this->httpHeaders[$name])) {
			$retval = true;
		}
		return $retval;
	}

	/**
	 * Set a HTTP header for the response
	 * @param      string $name A HTTP header field name.
	 * @param      mixed $value A HTTP header field value, of an array of values.
	 * @param      bool $replace If true, a header with that name will be overwritten,
	 *                    otherwise, the value will be appended.
	 * @return     void
	 * @since      1.0.0
	 */
	public function setHttpHeader($name, $value, $replace = true)
	{
		$name = $this->normalizeHttpHeaderName($name);
		// HTTP header values are strings on the wire; normalize scalars (e.g. an
		// int Content-Length) so the stored representation is always list<string>.
		$newValues = is_array($value)
			? array_map(self::toStringOrEmpty(...), array_values($value))
			: [self::toStringOrEmpty($value)];
		// Captured before mutating so a PSR-7 rejection below can put the header
		// back exactly as it was, rather than dropping values that were already
		// set and perfectly legal.
		$previous = $this->httpHeaders[$name] ?? null;
		if(!isset($this->httpHeaders[$name]) || $replace) {
			$this->httpHeaders[$name] = $newValues;
		} else {
			foreach($newValues as $nv) {
				$this->httpHeaders[$name][] = $nv;
			}
		}
		if($this->psrResponse !== null) {
			$psrBefore = $this->psrResponse;
			try {
				if($replace) {
					$this->psrResponse = $this->psrResponse->withHeader($name, $this->httpHeaders[$name]);
				} else {
					foreach($newValues as $v) {
						$this->psrResponse = $this->psrResponse->withAddedHeader($name, $v);
					}
				}
			} catch(\Throwable $e) {
				// PSR-7 rejected the name or value (illegal characters, CR/LF).
				// Swallowing this silently left the two representations of the same
				// response disagreeing: the value stayed in $httpHeaders and so was
				// still emitted by send()'s header() path under a classic SAPI, while
				// the PSR-7 response a worker runtime returns never carried it. Roll
				// both back to their pre-call state and say so, instead of emitting a
				// header on one runtime and not the other.
				$this->psrResponse = $psrBefore;
				if($previous === null) {
					unset($this->httpHeaders[$name]);
				} else {
					$this->httpHeaders[$name] = $previous;
				}
				\Quiote\Logging\Log::for($this)->warning(
					'[WebResponse] rejected HTTP header "' . $name . '": ' . $e->getMessage()
					. ' -- not set on this response.'
				);
			}
		}
	}

	/**
	 * @param      string $name A HTTP header field name.
	 * @param      mixed  $value A HTTP header field value, or an array of values.
	 * @return     void
	 */
	public function addHttpHeader($name, $value)
	{
		$this->setHttpHeader($name, $value, false);
	}

	/**
	 * The serializer for this response's cookies, built with the routing base path so a
	 * cookie that declares no path of its own inherits the application's.
	 */
	private function cookieSerializer(): CookieSerializer
	{
		$path = '/';
		$clock = null;
		try {
			$container = $this->context?->getContainer();
			$base = $container?->get(\Quiote\Routing\Routing::class)?->getBasePath();
			if(is_string($base) && $base !== '') {
				$path = $base;
			}
			$clock = $container?->get(\Quiote\Support\Clock\ClockInterface::class);
		} catch(\Throwable $e) {
			\Quiote\Logging\Log::for($this)->debug(
				'[WebResponse] routing base path unavailable for cookie scoping, using "/": ' . $e->getMessage()
			);
		}

		return $clock instanceof \Quiote\Support\Clock\ClockInterface
			? new CookieSerializer($path, $clock)
			: new CookieSerializer($path);
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private function logCookieDebug(string $stage, array $context = []): void
	{
		$logger = \Quiote\Logging\Log::for($this);
		if(!$logger->isEnabled(\Quiote\Logging\Level::Debug)) {
			return;
		}
		$payload = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if($payload === false) {
			$payload = '[unserializable context]';
		}
		$logger->debug('[WebResponse][' . $stage . '] ' . $payload);
	}

	/**
	 * Send a cookie.
	 * @param      string         A cookie name.
	 * @param      mixed          Data to store into a cookie. If null or empty cookie
	 *                            will be tried to be removed.
	 * @param      mixed          The lifetime of the cookie in seconds. When you pass 0 
	 *                            the cookie will be valid until the browser is closed.
	 *                            You can also use a strtotime() string instead of an int.
	 * @param      string         The path on the server the cookie will be available on.
	 * @param      string         The domain the cookie is available on.
	 * @param      bool           Indicates that the cookie should only be transmitted 
	 *                            over a secure HTTPS connection.
	 * @param      bool           Whether the cookie will be made accessible only through
	 *                            the HTTP protocol, and not to client-side scripts.
	 * @param      callable|bool  Callback to encode the cookie value. Set to false
	 *                            if you did already encode the value on your own.
	 * @throws     Exception If $encodeCallback is neither false nor callable.
	 * @since      1.0.0
	 */
	/**
	 * Determine whether the given request arrived over HTTPS, working for both an
	 * WebRequest and a raw PSR-7 ServerRequestInterface.
	 * method_exists('isHttps') only tells us the request *type*, not the scheme, so
	 * for PSR-7 requests (which never define isHttps()) we read the actual scheme
	 * from the URI / server params / forwarded headers instead of assuming plain HTTP.
	 * @param      object $request The request (WebRequest or PSR-7 ServerRequestInterface).
	 * @return     bool
	 */
	private static function requestIsHttps(object $request): bool
	{
		// Native Quiote request knows its own scheme (and honors proxy config).
		if (method_exists($request, 'isHttps')) {
			return (bool) $request->isHttps();
		}

		// PSR-7: trust the URI scheme first.
		if (method_exists($request, 'getUri')) {
			try {
				if (strtolower((string) $request->getUri()->getScheme()) === 'https') {
					return true;
				}
			} catch (\Throwable $e) {
				// Falls through to the forwarded-proto check below. Reported because this
				// answer decides cookie_secure: read as not-HTTPS, a cookie goes out without
				// the Secure attribute and can then travel in clear.
				\Quiote\Logging\Log::create(self::class)->warning(
					'[WebResponse] could not read the request scheme for HTTPS detection; '
					. 'cookies may not be marked Secure: ' . $e->getMessage()
				);
			}
		}

		// PSR-7 behind a TLS-terminating proxy, or built from globals: fall back to
		// server params / forwarded headers.
		$server = [];
		if (method_exists($request, 'getServerParams')) {
			try { $server = (array) $request->getServerParams(); } catch (\Throwable) { $server = []; }
		}
		if (isset($server['HTTPS']) && $server['HTTPS'] !== '' && strtolower((string) $server['HTTPS']) !== 'off') {
			return true;
		}
		if (isset($server['REQUEST_SCHEME']) && strtolower((string) $server['REQUEST_SCHEME']) === 'https') {
			return true;
		}
		if (method_exists($request, 'getHeaderLine')) {
			try {
				$xfp = strtolower(trim((string) $request->getHeaderLine('X-Forwarded-Proto')));
				if ($xfp !== '' && str_starts_with($xfp, 'https')) {
					return true;
				}
			} catch (\Throwable $e) {
				// Last HTTPS signal available; without it this request is treated as plain HTTP
				// and its cookies are not marked Secure.
				\Quiote\Logging\Log::create(self::class)->warning(
					'[WebResponse] could not read X-Forwarded-Proto for HTTPS detection; '
					. 'cookies may not be marked Secure: ' . $e->getMessage()
				);
			}
		}

		return false;
	}

	/**
	 * @param      string        $name A cookie name.
	 * @param      mixed         $value Data to store into a cookie. If null or empty cookie
	 *                           will be tried to be removed.
	 * @param      mixed         $lifetime The lifetime of the cookie in seconds. When you pass 0
	 *                           the cookie will be valid until the browser is closed.
	 *                           You can also use a strtotime() string instead of an int.
	 * @param      ?string       $path The path on the server the cookie will be available on.
	 * @param      ?string       $domain The domain the cookie is available on.
	 * @param      ?bool         $secure Indicates that the cookie should only be transmitted
	 *                           over a secure HTTPS connection.
	 * @param      ?bool         $httponly Whether the cookie will be made accessible only through
	 *                           the HTTP protocol, and not to client-side scripts.
	 * @param      mixed         $encodeCallback Callback to encode the cookie value. Set to false
	 *                           if you did already encode the value on your own.
	 * @param      ?string       $samesite The SameSite attribute for the cookie.
	 * @return     void
	 */
	public function setCookie($name, $value, $lifetime = null, $path = null, $domain = null, $secure = null, $httponly = null, $encodeCallback = null, $samesite = null)
	{
		$lifetime ??= $this->getParameter('cookie_lifetime');
		$path ??= $this->getParameter('cookie_path');
		$domain ??= $this->getParameter('cookie_domain');
		$secure         = (bool) ($secure ?? $this->getParameter('cookie_secure'));
		$httponly       = (bool) ($httponly ?? $this->getParameter('cookie_httponly'));
		$encodeCallback ??= $this->getParameter('cookie_encode_callback');
		$samesite ??= $this->getParameter('cookie_samesite');

		if($encodeCallback !== false && !is_callable($encodeCallback)) {
			throw new QuioteException(sprintf('setCookie() $encodeCallback argument is not callable: %s', get_debug_type($encodeCallback)));
		}

		// Normalize the config-sourced (mixed) attributes to the stored cookie shape.
		$this->cookies[$name] = [
			'value' => $value,
			'lifetime' => (is_int($lifetime) || is_string($lifetime)) ? $lifetime : null,
			'path' => is_string($path) ? $path : null,
			'domain' => is_string($domain) ? $domain : null,
			'secure' => $secure,
			'httponly' => $httponly,
			'encode_callback' => $encodeCallback,
			'samesite' => is_string($samesite) ? $samesite : null,
		];
		$this->logCookieDebug('setCookie', [
			'name' => $name,
			'raw' => $this->cookies[$name],
		]);
		if($this->psrResponse !== null) {
			$serializer = $this->cookieSerializer();
			$normalized = $serializer->normalize($name, $this->cookies[$name]);
			$header = $serializer->header($name, $normalized);
			$this->psrResponse = $this->psrResponse->withAddedHeader('Set-Cookie', $header);
			$this->logCookieDebug('psrResponseSetCookie', [
				'name' => $name,
				'normalized' => $normalized,
				'header' => $header,
			]);
		}
	}

	/**
	 * Unset an existing cookie.
	 * All arguments must reflect the values of the cookie that is already set.
	 * @param      string $name A cookie name.
	 * @param      string $path The path on the server the cookie will be available on.
	 * @param      string $domain The domain the cookie is available on.
	 * @param      bool $secure Indicates that the cookie should only be transmitted 
	 *                    over a secure HTTPS connection.
	 * @param      bool $httponly Whether the cookie will be made accessible only through
	 *                    the HTTP protocol, and not to client-side scripts.
	 * @return     void
	 * @since      1.0.0
	 */
	public function unsetCookie($name, $path = null, $domain = null, $secure = null, $httponly = null)
	{
		// false as the value, triggers deletion
		// null for the lifetime, since Quiote automatically sets that when the value is false or null
		$this->setCookie($name, false, null, $path, $domain, $secure, $httponly);
	}

	/**
	 * Get a cookie set for later sending.
	 * @param      string $name The name of the cookie.
	 * @return     ?array<string, mixed> An associative array containing the cookie data or null
	 *                   if no cookie with that name has been set.
	 * @since      1.0.0
	 */
	public function getCookie($name)
	{
		if(isset($this->cookies[$name])) {
			return $this->cookies[$name];
		}

		return null;
	}

	/**
	 * Check if a cookie has been set for later sending.
	 * @param      string $name The name of the cookie.
	 * @return     bool True if a cookie with that name has been set, else false.
	 * @since      1.0.0
	 */
	public function hasCookie($name)
	{
		return isset($this->cookies[$name]);
	}

	/**
	 * Remove a cookie previously set for later sending.
	 * This method cannot be used to unset a cookie. It's purpose is to remove a
	 * cookie from the list of cookies to be sent along with the response. If you
	 * wish to remove an existing cookie, use the setCookie method and supply null
	 * as the value.
	 * @param      string $name The name of the cookie.
	 * @return     void
	 * @since      1.0.0
	 */
	public function removeCookie($name)
	{
		if(isset($this->cookies[$name])) {
			unset($this->cookies[$name]);
		}
	}

	/**
	 * Get a list of cookies set for later sending.
	 * @return     array<string, array{value: mixed, lifetime: int|string|null, path: string|null, domain: string|null, secure: bool, httponly: bool, encode_callback: callable|false, samesite: string|null}> An associative array of cookie names (key) and cookie
	 *                   information (value, associative array).
	 * @since      1.0.0
	 */
	public function getCookies()
	{
		return $this->cookies;
	}

	/**
	 * Remove the HTTP header set for the response
	 * @param      string $name A HTTP header field name.
	 * @return     mixed The removed header's value or null if header was not set.
	 * @since      1.0.0
	 */
	public function removeHttpHeader($name)
	{
		$name = $this->normalizeHttpHeaderName($name);
		$retval = null;
		if(isset($this->httpHeaders[$name])) {
			$retval = $this->httpHeaders[$name];
			unset($this->httpHeaders[$name]);
		}
		if($this->psrResponse !== null) {
			try {
				$this->psrResponse = $this->psrResponse->withoutHeader($name);
			} catch(\Throwable $e) {
				// Removed here but still present on the attached PSR-7 response, so a forwarded
				// response can keep sending a header this one dropped.
				\Quiote\Logging\Log::for($this)->warning(
					'[WebResponse] could not remove header "' . $name
					. '" from the attached PSR-7 response: ' . $e->getMessage()
				);
			}
		}
		return $retval;
	}

	/**
	 * Clears the HTTP headers set for this response.
	 * @return     void
	 * @since      1.0.0
	 */
	public function clearHttpHeaders()
	{
		$this->httpHeaders = [];
	}

	/**
	 * Fold the output type's headers, Content-Length and X-Powered-By into
	 * $this->httpHeaders, ready to be materialized onto a PSR-7 response.
	 *
	 * Preparation only: this used to call header() and so was the transport step
	 * as well, which is exactly what made it work on a SAPI and silently do
	 * nothing anywhere else. Transport now belongs to the runtime's
	 * {@see \Quiote\Runtime\Emitter\ResponseEmitterInterface}, reached via
	 * {@see toPsrResponse()}.
	 * @return     void
	 * @since      1.0.0
	 */
	protected function prepareHttpResponseHeaders(?OutputType $outputType = null)
	{
		if($outputType === null) {
			$outputType = $this->getOutputType();
		}

		if($outputType !== null) {
			$httpHeaders = $outputType->getParameter('http_headers');
			if(!is_array($httpHeaders)) {
				$httpHeaders = [];
			}
			foreach($httpHeaders as $name => $value) {
				if(!$this->hasHttpHeader($name)) {
					$this->setHttpHeader($name, $value);
				}
			}
		}

		if($this->getParameter('send_content_length', true) && !$this->hasHttpHeader('Content-Length') && ($contentSize = $this->getContentSize()) !== false) {
			$this->setHttpHeader('Content-Length', $contentSize);
		}

		if($this->getParameter('expose_quiote', true) && !$this->hasHttpHeader('X-Powered-By')) {
			$expose_php = (bool) ini_get('expose_php');
			if(Config::getBool('core.expose_quiote_version', $expose_php)) {
				$xpbh = Config::getString('quiote.release');
			} else {
				$xpbh = Config::getString('quiote.name');
			}
			if($expose_php) {
				$xpbh .= ' on PHP/' . PHP_VERSION;
			}
			$this->setHttpHeader('X-Powered-By', $xpbh);
		}

	}

	/**
	 * The Set-Cookie header lines for every cookie queued on this response.
	 * @return     list<string>
	 * @since      3.1.2
	 */
	private function buildSetCookieHeaders(): array
	{
		$serializer = $this->cookieSerializer();
		$lines = [];
		foreach($this->cookies as $name => $values) {
			$normalized = $serializer->normalize($name, $values);
			$headerValue = $serializer->header($name, $normalized);
			$lines[] = $headerValue;
			$this->logCookieDebug('sendCookieHeader', [
				'name' => $name,
				'normalized' => $normalized,
				'header' => $headerValue,
			]);
		}
		return $lines;
	}

	/**
	 * Redirect externally.
	 * @param      mixed $location Where to redirect.
	 * @param      int|string $code A numeric HTTP status code.
	 * @return     void
	 * @since      1.0.0
	 */
	public function setRedirect($location, $code = 302)
	{
		if(!$this->validateHttpStatusCode($code)) {
			throw new QuioteException(sprintf(
				'Invalid HTTP redirect status code: %s (expected %d-%d)',
				(string) $code,
				\Quiote\Http\HttpStatus::MIN,
				\Quiote\Http\HttpStatus::MAX,
			));
		}
		$this->redirect = [
			'location' => self::toStringOrEmpty($location),
			'code' => is_int($code) ? $code : self::toStringOrEmpty($code),
		];
	}

	/**
	 * Get info about the set redirect.
	 * @return     ?array{location: string, code: int|string} An assoc array of redirect info, or null if none set.
	 * @since      1.0.0
	 */
	public function getRedirect()
	{
		return $this->redirect;
	}

	/**
	 * Check if a redirect is set.
	 * @return     bool true, if a redirect is set, otherwise false
	 * @since      1.0.0
	 */
	public function hasRedirect()
	{
		return $this->redirect !== null;
	}

	/**
	 * Clear any set redirect information.
	 * @return     void
	 * @since      1.0.0
	 */
	public function clearRedirect()
	{
		$this->redirect = null;
	}

}

?>