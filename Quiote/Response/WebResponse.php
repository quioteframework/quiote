<?php
namespace Quiote\Response;

use Quiote\Context;
use Quiote\Config\Config;
use Quiote\Controller\OutputType;
use Quiote\Exception\QuioteException;
use Quiote\Request\WebRequest;
use Quiote\Response\Response;
use Quiote\Util\AttributeHolder;
use Symfony\Contracts\Service\ResetInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Http\SimpleStream;

/**
 * WebResponse handles HTTP responses.
 * @since      1.0.0
 * @version    1.0.0
 */
class WebResponse extends Response
{


	/**
	 * @var        array<int, string> An array of all HTTP 1.0 status codes and their message.
	 */
	protected $http10StatusCodes = [
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
	 * @var        array<int, string> An array of all HTTP 1.1 status codes and their message.
	 */
	protected $http11StatusCodes = [
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
		* @var        ?array<int, string> The array with the HTTP status codes to be used here.
		*/
	protected $httpStatusCodes = null;

	/**
	 * @var        string The HTTP status code to send for the response.
	 */
	protected $httpStatusCode = '200';

	/**
	 * @var        array<string, array<int, mixed>> The HTTP headers scheduled to be sent with the response.
	 */
	protected $httpHeaders = [];

	/**
	 * @var        array<string, array<string, mixed>> The Cookies scheduled to be sent with the response.
	 */
	protected $cookies = [];

	/**
	 * @var        ?array{location: mixed, code: int|string} An array of redirect information, or null if no redirect.
	 */
	protected $redirect = null;

	// --- Begin merged Response properties ---
	/** @var ?Context */
	protected $context = null;
	/** @var mixed */
	protected $content = null;
	/** @var ?OutputType */
	protected $outputType = null;

	/** @var ?ResponseInterface PSR-7 response attached for forwarding */
	protected ?ResponseInterface $psrResponse = null;
	// --- End merged Response properties ---

	// --- Begin Response methods merged ---
	#[\Override]
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
			} catch(\Throwable) {}
		}
	}

	public function getPsrResponse(): ?ResponseInterface
	{
		return $this->psrResponse;
	}

	/**
	 * @return int|false The content size in bytes, or false if it could not be determined.
	 */
	#[\Override]
    public function getContentSize()
	{
		if (is_resource($this->content)) {
			if (($stat = fstat($this->content)) !== false) {
				return $stat['size'];
			} else {
				return false;
			}
		} else {
			return strlen((string) $this->content);
		}
	}

	#[\Override]
    public function setContent($content)
	{
		$this->content = $content;
	}

	#[\Override]
    public function prependContent($content)
	{
		$this->setContent($content . $this->getContent());
	}

	#[\Override]
    public function appendContent($content)
	{
		$this->setContent($this->getContent() . $content);
	}

	#[\Override]
    public function clearContent()
	{
		$this->content = null;
	}

	#[\Override]
    public function getOutputType()
	{
		return $this->outputType;
	}

	#[\Override]
    public function setOutputType(OutputType $outputType)
	{
		$this->outputType = $outputType;
	}

	#[\Override]
    public function clearOutputType()
	{
		$this->outputType = null;
	}

	#[\Override]
    public function reset(): void
	{
		// Reset web-specific response properties
		$this->httpStatusCode = '200';
		$this->httpHeaders = [];
		$this->cookies = [];
		$this->redirect = null;
		$this->httpStatusCodes = null;

		// Clear attribute holder state
		$this->clearAttributes();
		$this->clearParameters();
	}
	// --- End Response methods merged ---

	/**
	 * Initialize this Response.
	 * @param      Context $context An Context instance.
	 * @param      array<string, mixed> $parameters An array of initialization parameters.
	 * @return     void
	 * @since      1.0.0
	 */
	#[\Override]
    public function initialize(Context $context, array $parameters = [])
	{
		// Merge legacy Response::initialize behaviour
		$this->context = $context;
		$this->setParameters($parameters);

		/** @var ?WebRequest */
		$request = null;
		try {
			$request = $context->getRequest();
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

		if ($request) {
			$protocol = $request->getProtocol();
		} else {
			$protocol = 'HTTP/1.1';
		}
		$this->httpStatusCodes = match ($protocol) {
			'HTTP/2' => $this->http11StatusCodes,
			'HTTP/1.1' => $this->http11StatusCodes,
			default => $this->http10StatusCodes,
		};
	}

	/**
	 * Get the HTTP protocol string from a request object.
	 * Supports both WebRequest::getProtocol() and PSR-7 getProtocolVersion().
	 * @param      mixed $request A request object or null.
	 * @return     string The HTTP protocol (e.g., "HTTP/1.1").
	 */
	protected function getRequestProtocol($request): string
	{
		if ($request && method_exists($request, 'getProtocol')) {
			return $request->getProtocol();
		} elseif ($request && method_exists($request, 'getProtocolVersion')) {
			return 'HTTP/' . $request->getProtocolVersion();
		}
		return 'HTTP/1.1';
	}

	/**
	 * Send all response data to the client.
	 * @param      OutputType $outputType An optional Output Type object with information
	 *                             the response can use to send additional data,
	 *                             such as HTTP headers
	 * @return     void
	 * @since      1.0.0
	 */
	public function send(?OutputType $outputType = null)
	{
		if($this->redirect) {
			$location = $this->redirect['location'];
			if(!preg_match('#^[^:]+://#', (string) $location)) {
				if(isset($location[0]) && $location[0] == '/') {
					/** @var WebRequest */
					$rq = $this->context->getRequest();
					$location = $rq->getUrlScheme() . '://' . $rq->getUrlAuthority() . $location;
				} else {
					$location = $this->context->getRouting()->getBaseHref() . $location;
				}
			}
			$this->setHttpHeader('Location', $location);
			$this->setHttpStatusCode($this->redirect['code']);
			if($this->getParameter('send_content_length', true) && !$this->hasHttpHeader('Content-Length') && !$this->getParameter('send_redirect_content', false)) {
				$this->setHttpHeader('Content-Length', 0);
			}
		}
		$this->sendHttpResponseHeaders($outputType);
		if(!$this->redirect || $this->getParameter('send_redirect_content', false)) {
			$this->sendContent();
		}
	}

	/**
	 * Send the content for this response
	 * @return     void
	 * @since      1.0.0
	 */
	#[\Override]
    public function sendContent()
	{
		if(is_resource($this->content) && $this->getParameter('use_sendfile_header', false)) {
			$info = stream_get_meta_data($this->content);
			if($info['wrapper_type'] == 'plainfile') {
				header($this->getParameter('sendfile_header_name', 'X-Sendfile') . ': ' . $info['uri']);
				return;
			}
		}
		// Inline Response::sendContent behaviour
		if(is_resource($this->content)) {
			fpassthru($this->content);
			fclose($this->content);
		} else {
			echo $this->content;
			if($this->psrResponse !== null) {
				try {
					$this->psrResponse = $this->psrResponse->withBody(SimpleStream::fromString((string)$this->content));
				} catch(\Throwable) {}
			}
		}
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
	}

	/**
	 * Check whether or not some content is set.
	 * @return     bool If any content is set, false otherwise.
	 * @since      1.0.0
	 */
    #[\Override]
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
	 * Import response metadata (headers, cookies) from another response.
	 * @param      Response $otherResponse The other response to import information from.
	 * @return     void
	 * @since      1.0.0
	 */
	#[\Override]
    public function merge($otherResponse)
	{
		// Merge attribute holder state (behaviour from Response::merge)
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

		if($otherResponse instanceof WebResponse) {
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
			if($otherResponse->hasRedirect() && !$this->hasRedirect()) {
				$redirect = $otherResponse->getRedirect();
				$this->setRedirect($redirect['location'], $redirect['code']);
			}
		}
	}

		/**
		 * Determine whether the content in the response may be modified by appending
		 * or prepending data using string operations. Typically false for streams
		 * or responses where the content is not a string (e.g. an array).
		 * @return     bool If the content can be treated as / changed like a string.
		 */
		#[\Override]
        public function isContentMutable()
		{
			return !$this->hasRedirect() && !is_resource($this->content);
		}

	/**
	 * Check if the given HTTP status code is valid.
	public function hasContent()
	 * @param      string|int $code A numeric HTTP status code.
	 * @return     bool True, if the code is valid, or false otherwise.
	 * @since      1.0.0
	 */
	public function validateHttpStatusCode($code)
	{
		$code = (string)$code;
		$codes = $this->httpStatusCodes ?? $this->http11StatusCodes;
		return isset($codes[$code]);
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
				} catch(\Throwable) {}
			}
		} else {
			$request = $this->context?->getRequest();
			$protocol = $this->getRequestProtocol($request);
			throw new QuioteException(sprintf('Invalid %s Status code: %s', $protocol, $code));
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
		if(strtolower((string) $name) == "etag") {
			return "ETag";
		} elseif(strtolower((string) $name) == "www-authenticate") {
			return "WWW-Authenticate";
		} else {
			return str_replace(' ', '-', ucwords(str_replace('-', ' ', strtolower((string) $name))));
		}
	}

	/**
	 * Retrieve the HTTP header values set for the response.
	 * @param      string $name A HTTP header field name.
	 * @return     ?array<int, mixed> All values set for that header, or null if no headers set
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
	 * @return     array<string, array<int, mixed>> An associative array of HTTP header names and values.
	 * @since      1.0.0
	 */
	public function getHttpHeaders()
	{
		return $this->httpHeaders;
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
		$newValues = is_array($value) ? array_values($value) : [$value];
		if(!isset($this->httpHeaders[$name]) || $replace) {
			$this->httpHeaders[$name] = $newValues;
		} else {
			foreach($newValues as $nv) {
				$this->httpHeaders[$name][] = $nv;
			}
		}
		if($this->psrResponse !== null) {
			try {
				if($replace) {
					$this->psrResponse = $this->psrResponse->withHeader($name, $this->httpHeaders[$name]);
				} else {
					foreach($newValues as $v) {
						$this->psrResponse = $this->psrResponse->withAddedHeader($name, $v);
					}
				}
			} catch(\Throwable) {}
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
	 * @param array<string, mixed> $cookie
	 * @return array{
	 *   value: string,
	 *   expires: ?int,
	 *   max_age: ?int,
	 *   path: string,
	 *   domain: ?string,
	 *   secure: bool,
	 *   httponly: bool,
	 *   samesite: ?string
	 * }
	 */
	private function normalizeCookieForSend(string $name, array $cookie): array
	{
		$now = time();
		$value = $cookie['value'];
		$encodeCallback = $cookie['encode_callback'];
		$shouldDelete = ($value === false || $value === null || $value === '');
		if(!$shouldDelete && $encodeCallback) {
			try {
				$value = call_user_func($encodeCallback, $value);
			} catch(\Throwable) {
				// Fall back to raw value on encoding failure
			}
		}
		if($shouldDelete) {
			$value = '';
			$expires = $now - 86400;
			$maxAge = 0;
		} else {
			$expires = null;
			$lifetime = $cookie['lifetime'];
			if(is_string($lifetime) && $lifetime !== '') {
				$parsed = strtotime($lifetime);
				if($parsed !== false) {
					$expires = $parsed;
				}
			} elseif(is_numeric($lifetime)) {
				$lifetime = (int)$lifetime;
				if($lifetime > 0) {
					$expires = $now + $lifetime;
				}
			}
			$maxAge = $expires !== null ? max(0, $expires - $now) : null;
		}
		$path = $cookie['path'];
		if($path === null) {
			$path = '/';
			try {
				$routing = $this->context?->getRouting();
				if($routing) {
					$base = $routing->getBasePath();
					if($base !== '') {
						$path = $base;
					}
				}
			} catch(\Throwable) {
				// ignore routing lookup failures
			}
		}
		if($path === '') {
			$path = '/';
		}
		$domain = $cookie['domain'] ?? null;
		$secure = !empty($cookie['secure']);
		$httponly = !empty($cookie['httponly']);
		$samesite = $cookie['samesite'] ?? null;
		if(is_string($samesite) && $samesite !== '') {
			$samesite = ucfirst(strtolower($samesite));
		} else {
			$samesite = null;
		}
		return [
			'value' => (string)$value,
			'expires' => $expires,
			'max_age' => $maxAge,
			'path' => $path,
			'domain' => $domain !== '' ? $domain : null,
			'secure' => $secure,
			'httponly' => $httponly,
			'samesite' => $samesite,
		];
	}

	/**
	 * @param array{
	 *   value: string,
	 *   expires: ?int,
	 *   max_age: ?int,
	 *   path: string,
	 *   domain: ?string,
	 *   secure: bool,
	 *   httponly: bool,
	 *   samesite: ?string
	 * } $normalized
	 */
	private function buildSetCookieHeader(string $name, array $normalized): string
	{
		$parts = [];
		$parts[] = $name . '=' . $normalized['value'];
		if($normalized['expires'] !== null) {
			$parts[] = 'Expires=' . gmdate('D, d-M-Y H:i:s T', $normalized['expires']);
			$maxAge = $normalized['max_age'];
			if($maxAge !== null) {
				$parts[] = 'Max-Age=' . $maxAge;
			}
		} elseif($normalized['max_age'] === 0) {
			$parts[] = 'Max-Age=0';
		}
		if($normalized['path'] !== '') {
			$parts[] = 'Path=' . $normalized['path'];
		}
		if($normalized['domain']) {
			$parts[] = 'Domain=' . $normalized['domain'];
		}
		if($normalized['secure']) {
			$parts[] = 'Secure';
		}
		if($normalized['httponly']) {
			$parts[] = 'HttpOnly';
		}
		if($normalized['samesite']) {
			$parts[] = 'SameSite=' . $normalized['samesite'];
		}
		return implode('; ', $parts);
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
			} catch (\Throwable) {
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
			} catch (\Throwable) {
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
			throw new QuioteException(sprintf('setCookie() $encodeCallback argument is not callable: %s', $encodeCallback));
		}

		$this->cookies[$name] = [
			'value' => $value,
			'lifetime' => $lifetime,
			'path' => $path,
			'domain' => $domain,
			'secure' => $secure,
			'httponly' => $httponly,
			'encode_callback' => $encodeCallback,
			'samesite' => $samesite
		];
		$this->logCookieDebug('setCookie', [
			'name' => $name,
			'raw' => $this->cookies[$name],
		]);
		if($this->psrResponse !== null) {
			try {
				$normalized = $this->normalizeCookieForSend($name, $this->cookies[$name]);
				$header = $this->buildSetCookieHeader($name, $normalized);
				$this->psrResponse = $this->psrResponse->withAddedHeader('Set-Cookie', $header);
				$this->logCookieDebug('psrResponseSetCookie', [
					'name' => $name,
					'normalized' => $normalized,
					'header' => $header,
				]);
			} catch(\Throwable) {}
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
	 * @return     array<string, array<string, mixed>> An associative array of cookie names (key) and cookie
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
			try { $this->psrResponse = $this->psrResponse->withoutHeader($name); } catch(\Throwable) {}
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
	 * Sends HTTP Status code, headers and cookies
	 * @return     void
	 * @since      1.0.0
	 */
	protected function sendHttpResponseHeaders(?OutputType $outputType = null)
	{
		if($outputType === null) {
			$outputType = $this->getOutputType();
		}

		// send HTTP status code
		if(isset($this->httpStatusCodes[$this->httpStatusCode])) {
			header($this->httpStatusCodes[$this->httpStatusCode]);
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

		// send cookies
		foreach($this->cookies as $name => $values) {
			$normalized = $this->normalizeCookieForSend($name, $values);
			$headerValue = $this->buildSetCookieHeader($name, $normalized);
			header('Set-Cookie: ' . $headerValue, false);
			$this->logCookieDebug('sendCookieHeader', [
				'name' => $name,
				'normalized' => $normalized,
				'header' => $headerValue,
			]);
		}

		// send headers
		foreach($this->httpHeaders as $name => $values) {
			foreach($values as $key => $value) {
				if($key == 0) {
					header($name . ': ' . $value);
				} else {
					header($name . ': ' . $value, false);
				}
			}
		}
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
			$request = $this->context->getRequest();
			$protocol = $this->getRequestProtocol($request);
			throw new QuioteException(sprintf('Invalid %s Redirect Status code: %s', $protocol, $code));
		}
		$this->redirect = ['location' => $location, 'code' => $code];
	}

	/**
	 * Get info about the set redirect.
	 * @return     ?array{location: mixed, code: int|string} An assoc array of redirect info, or null if none set.
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

	/**
	 * Reset web response state for FrankenPHP worker compatibility.
	 * Clears web-specific response properties that could leak between requests.
	 * @since      1.0.0
	 */
	// duplicate reset removed (merged earlier)
}

?>