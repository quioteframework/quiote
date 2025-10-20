<?php

// +---------------------------------------------------------------------------+
// | This file is part of the Agavi package.                                   |
// | Copyright (c) 2005-2011 the Agavi Project.                                |
// | Based on the Mojavi3 MVC Framework, Copyright (c) 2003-2005 Sean Kerr.    |
// |                                                                           |
// | For the full copyright and license information, please view the LICENSE   |
// | file that was distributed with this source code. You can also view the    |
// | LICENSE file online at http://www.agavi.org/LICENSE.txt                   |
// |   vi: set noexpandtab:                                                    |
// |   Local Variables:                                                        |
// |   indent-tabs-mode: t                                                     |
// |   End:                                                                    |
// +---------------------------------------------------------------------------+
namespace Agavi\Request;

use Agavi\AgaviContext;
use Agavi\Exception\AgaviException;
use Agavi\Logging\AgaviDebugLogger;
use Agavi\Util\AgaviArrayPathDefinition;
use Agavi\Util\AgaviToolkit;
use InvalidArgumentException;
use Negotiation\Exception\InvalidArgument;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * AgaviWebRequest provides additional support for web-only client requests
 * such as cookie and file manipulation.
 *
 * @package    agavi
 * @subpackage request
 *
 * @author     Sean Kerr <skerr@mojavi.org>
 * @author     Veikko Mäkinen <mail@veikkomakinen.com>
 * @author     David Zülke <dz@bitxtender.com>
 * @copyright  Authors
 * @copyright  The Agavi Project
 *
 * @since      0.9.0
 *
 * @version    $Id$
 */
/**
 * @method string getUrlScheme()
 * @method string getUrlHost()
 * @method int    getUrlPort()
 * @method string getUrlAuthority(bool $forcePort = false)
 * @method string getRequestUri()
 * @method string getUrlPath()
 * @method string getUrlQuery()
 * @method string getUrl()
 *
 * NOTE: These methods already exist concretely; annotations help static analyzers when
 * code references the web request through the abstract AgaviRequest type.
 *
 * TODO(PSR-7 Migration): Long term this class should become an adapter around a PSR-7
 * ServerRequestInterface implementation (see Middleware layer using PSR-7 already).
 * Migration steps proposed:
 * 1. Introduce AgaviPsrRequestAdapter implementing minimal AgaviRequest API via composition.
 * 2. Deprecate direct mutation setters (setUrlScheme, setUrlHost, etc.) in favor of immutable
 *    withModified* variants aligning with PSR-7 semantics.
 * 3. Gradually type-hint against Psr\Http\Message\ServerRequestInterface where feasible
 *    (routing, dispatch) and use adapter only for legacy consumers.
 */
class AgaviWebRequest implements ServerRequestInterface, ResetInterface
{

	// Trait 
	use Psr7RequestTrait;

	/**
	 * @var        string The protocol information of this request.
	 */
	protected $protocol = null;

	/**
	 * @var        string The current URL scheme.
	 */
	protected $urlScheme = '';

	/**
	 * @var        string The current URL authority.
	 */
	protected $urlHost = '';

	/**
	 * @var        string The current URL authority.
	 */
	protected $urlPort = 0;

	/**
	 * @var        string The current URL path.
	 */
	protected $urlPath = '';

	/**
	 * @var        string The current URL query.
	 */
	protected $urlQuery = '';

	/**
	 * @var        string The current request URL (path and query).
	 */
	protected $requestUri = '';

	/**
	 * @var        string The current URL.
	 */
	protected $url = '';

	// Wrapped PSR-7 request; all interface methods delegate here
	private ?ServerRequestInterface $request = null;

	// (Removed adaptedFiles cache – we now expose PSR-7 UploadedFileInterface instances directly.)

	/**
	 * Runtime (internal) parameters set via setParameter/appendParameter.
	 * These are distinct from HTTP input (query/body/cookies/headers/files) and
	 * from PSR-7 attributes. Historically these were stored in an
	 * AgaviRequestDataHolder. We keep them separate to enforce explicitness
	 * between user-supplied data and framework-injected context.
	 * @var array<string,mixed>
	 */
	private array $runtimeParameters = [];

	/**
	 * Strict validated parameter enforcement is ALWAYS active.
	 * Only parameters whitelisted in $validatedKeys may be accessed via
	 * getParameter()/hasParameter(). Unvalidated access ALWAYS throws.
	 */
	private array $validatedKeys = [];


	private array $sourceNames = ["parameters" => "parameter", "cookies" => "cookie", "files" => "file", "headers" => "header"];
	/**
	 * Checks if a field has no value (In web context this would only return true
	 * when the strings length is 0 or the field is not set.
	 *
	 * @param      string The name of the source to operate on.
	 * @param      string A field name.
	 *
	 * @return     bool The result.
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function isValueEmpty($source, $field)
	{
		$funcname = 'is' . $this->sourceNames[$source] . 'ValueEmpty';
		if (is_callable([$this, $funcname])) {
			return $this->$funcname($field);
		} else {
			throw new InvalidArgumentException("Invalid source name '$source'");
		}
	}

	/**
	 * Checks if there is a value of a parameter is empty or not set.
	 *
	 * @param      string The field name.
	 *
	 * @return     bool The result.
	 *
	 * @author     David Zülke <david.zuelke@bitextender.com>
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @since      0.11.0
	 */
	public function isParameterValueEmpty($field)
	{
		$value = $this->getParameter($field);
		$empty = ($value === null || $value === '');
		if (getenv('AGAVI_DEBUG_VALIDATION')) {
			try {
				AgaviDebugLogger::debug('[AgaviWebRequest][debug][isParameterValueEmpty] field=' . $field . ' empty=' . ($empty ? '1' : '0') . ' valueType=' . gettype($value));
			} catch (\Throwable) {
			}
		}
		return $empty;
	}


	/**
	 * Indicates whether or not a Cookie exists.
	 *
	 * @param      string A cookie name.
	 *
	 * @return     bool True, if a cookie with that name exists, otherwise false.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function hasCookie($name)
	{
		if (isset($this->cookies[$name]) || array_key_exists($name, $this->getCookieParams())) {
			return true;
		}
		try {
			return AgaviArrayPathDefinition::hasValue($name, $this->getCookieParams());
		} catch (InvalidArgumentException) {
			return false;
		}
	}

	/**
	 * Checks if there is a value of a cookie is empty or not set.
	 *
	 * @param      string The cookie name.
	 *
	 * @return     bool The result.
	 *
	 * @author     David Zülke <david.zuelke@bitextender.com>
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @since      0.11.0
	 */
	public function isCookieValueEmpty($name)
	{
		// Explicitly inspect cookie params to avoid indirect parameter precedence side-effects.
		$cookies = $this->getCookieParams();
		if(array_key_exists($name, $cookies)) {
			$val = $cookies[$name];
			return $val === null || $val === '';
		}
		return true;
	}


	/**
	 * Checks if there is a value of a header is empty or not set.
	 *
	 * @param      string The header name.
	 *
	 * @return     bool The result.
	 *
	 * @author     David Zülke <david.zuelke@bitextender.com>
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @since      0.11.0
	 */
	public function isHeaderValueEmpty($name)
	{
		// PSR-7 getHeader() returns an array; empty array means header absent.
		// We consider a header "empty" if it is not present OR if all values are
		// empty strings once concatenated (getHeaderLine == '').
		if(!$this->hasHeader($name)) {
			return true;
		}
		$line = $this->getHeaderLine($name);
		return ($line === '');
	}

	/**
	 * Checks if a file is empty, i.e. not set or set, but not actually uploaded.
	 *
	 * @param      string The file name.
	 *
	 * @return     bool The result.
	 *
	 * @author     David Zülke <david.zuelke@bitextender.com>
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @since      0.11.0
	 */
	/* Legacy stub removed; real implementation below uses UploadedFileInterface */


	/**
	 * Get the request protocol information, e.g. "HTTP/1.1".
	 *
	 * @return     string The protocol information.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function getProtocol()
	{
		return $this->protocol;
	}

	/**
	 * Retrieve the scheme part of a request URL, typically the protocol.
	 * Example: "http".
	 *
	 * @return     string The request URL scheme.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function getUrlScheme()
	{
		return $this->urlScheme;
	}

	/**
	 * Retrieve the hostname part of a request URL.
	 *
	 * @return     string The request URL hostname.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function getUrlHost()
	{
		return $this->urlHost;
	}

	/**
	 * Retrieve the hostname part of a request URL.
	 *
	 * @return     string The request URL hostname.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function getUrlPort()
	{
		// Late fallback to defaults if not yet inferred so we never expose :0
		if ($this->urlPort === 0) {
			if ($this->urlScheme === 'https') {
				return 443;
			}
			if ($this->urlScheme === 'http') {
				return 80;
			}
		}
		return $this->urlPort;
	}

	/**
	 * Retrieve the request URL authority, typically host and port.
	 * Example: "foo.example.com:8080".
	 *
	 * @param      bool Whether or not ports 80 (for HTTP) and 433 (for HTTPS)
	 *                  should be included in the return string.
	 *
	 * @return     string The request URL authority.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function getUrlAuthority($forcePort = false)
	{
		$port = $this->getUrlPort();
		$scheme = $this->getUrlScheme();
		return $this->getUrlHost() . ($forcePort || AgaviToolkit::isPortNecessary($scheme, $port) ? ':' . $port : '');
	}

	/**
	 * Retrieve the relative part of the request URL, i.e. path and query.
	 * Example: "/foo/bar/baz?id=4815162342".
	 *
	 * @return     string The relative URL of the current request.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function getRequestUri()
	{
		return $this->requestUri;
	}

	/**
	 * Retrieve the path part of the URL.
	 * Example: "/foo/bar/baz".
	 *
	 * @return     string The path part of the URL.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function getUrlPath()
	{
		return $this->urlPath;
	}

	/**
	 * Retrieve the query part of the URL.
	 * Example: "id=4815162342".
	 
	 * @return     string The query part of the URL, or an empty string.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function getUrlQuery()
	{
		return $this->urlQuery;
	}

	/**
	 * Retrieve the full request URL, including protocol, server name, port (if
	 * necessary), and request URI.
	 * Example: "http://foo.example.com:8080/foo/bar/baz?id=4815162342".
	 *
	 * @return     string The URL of the current request.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function getUrl()
	{
		return
			$this->getUrlScheme() . '://' .
			$this->getUrlAuthority() .
			$this->getRequestUri();
	}

	/**
	 * Whether or not HTTPS was used for this request.
	 *
	 * @return     bool True, if it's an HTTPS request, false otherwise.
	 *
	 * @author     David Zülke <david.zuelke@bitextender.com>
	 * @since      0.11.6
	 */
	public function isHttps()
	{
		return $this->getUrlScheme() == 'https';
	}

	/**
	 * Set the URL scheme for this request.
	 *
	 * @param      string The URL scheme (e.g., 'http', 'https').
	 *
	 * @since      2.0.0
	 */
	public function setUrlScheme($scheme)
	{
		$this->urlScheme = $scheme;
	}

	/**
	 * Set the URL host for this request.
	 *
	 * @param      string The URL host (e.g., 'example.com').
	 *
	 * @since      2.0.0
	 */
	public function setUrlHost($host)
	{
		$this->urlHost = $host;
	}

	/**
	 * Set the URL port for this request.
	 *
	 * @param      int The URL port (e.g., 80, 443, 8080).
	 *
	 * @since      2.0.0
	 */
	public function setUrlPort($port)
	{
		$this->urlPort = (int)$port;
	}

	/**
	 * Set the request URI for this request.
	 *
	 * @param      string The request URI (e.g., '/path/to/resource?query=value').
	 *
	 * @since      2.0.0
	 */
	public function setRequestUri($uri)
	{
		$this->requestUri = $uri;
	}

	/**
	 * Constructor.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	/**
	 * Initialize this Request (compat stub for factories.xml flow).
	 * We don't use Agavi's parameter holder anymore here.
	 */
	public function initialize(AgaviContext $context, array $parameters = [])
	{
		// Fallback for legacy flows: when no PSR-7 request is attached yet we still
		// need URL metadata derived from superglobals for helpers/tests.
		$this->bootstrapFromServerParams($_SERVER);
	}

	/**
	 * Attach a PSR-7 request and sync derived fields for BC helpers.
	 */
	public function attachPsrRequest(ServerRequestInterface $request): void
	{
		$this->request = $request;
		$uri = $request->getUri();
		$this->url = $uri->__toString();
		$this->urlScheme = (string) $uri->getScheme();
		$this->urlHost = (string) $uri->getHost();
		// Derive port robustly so we never leave it at 0 (which breaks URL generation):
		// Priority:
		//  1. Explicit port on URI
		//  2. SERVER_PORT from server params
		//  3. Default per scheme (http=80, https=443)
		$rawPort = $uri->getPort();
		if ($rawPort === null) {
			$sp = $request->getServerParams();
			if (isset($sp['SERVER_PORT']) && is_numeric($sp['SERVER_PORT'])) {
				$rawPort = (int) $sp['SERVER_PORT'];
			}
		}
		if ($rawPort === null || $rawPort === 0) {
			if ($this->urlScheme === 'https') {
				$rawPort = 443;
			} elseif ($this->urlScheme === 'http') {
				$rawPort = 80;
			}
		}
		$this->urlPort = (int) ($rawPort ?? 0); // Left as 0 only for unknown schemes
		$this->urlQuery = (string) $uri->getQuery();
		$this->urlPath = (string) $uri->getPath();
		$this->requestUri = $this->urlPath . ($this->urlQuery !== '' ? '?' . $this->urlQuery : '');
		$pv = $request->getProtocolVersion();
		$this->protocol = $pv !== '' ? 'HTTP/' . $pv : null;
	}

	/**
	 * Derive legacy URL metadata from PHP's server parameters when no PSR-7
	 * request is available (e.g. unit tests, early bootstrap flows).
	 */
	private function bootstrapFromServerParams(array $server): void
	{
		if ($this->request !== null) {
			return;
		}

		// Determine scheme with priority: explicit forwarded proto -> request scheme -> HTTPS flag.
		$scheme = '';
		if (!empty($server['HTTP_X_FORWARDED_PROTO'])) {
			$forwarded = explode(',', (string)$server['HTTP_X_FORWARDED_PROTO']);
			$scheme = strtolower(trim($forwarded[0]));
		}
		if ($scheme === '' && !empty($server['REQUEST_SCHEME'])) {
			$scheme = strtolower((string)$server['REQUEST_SCHEME']);
		}
		if ($scheme === '') {
			$https = $server['HTTPS'] ?? null;
			if (is_string($https)) {
				$flag = strtolower($https);
				if ($flag === 'on' || $flag === '1' || $flag === 'https') {
					$scheme = 'https';
				} elseif ($flag === 'off' || $flag === '0') {
					$scheme = 'http';
				}
			} elseif ($https === true) {
				$scheme = 'https';
			}
		}
		if ($scheme === '') {
			$scheme = 'http';
		}

		// Resolve host (and potential port) from Host header first, then server name/address.
		$hostHeader = $server['HTTP_HOST'] ?? '';
		if ($hostHeader === '') {
			$hostHeader = $server['SERVER_NAME'] ?? ($server['SERVER_ADDR'] ?? '');
		}
		$authority = '//' . ltrim((string)$hostHeader, '/');
		$parsedHost = parse_url($authority, PHP_URL_HOST);
		$parsedPort = parse_url($authority, PHP_URL_PORT);
		$host = is_string($parsedHost) ? $parsedHost : '';
		$port = ($parsedPort !== null && $parsedPort !== false) ? (int)$parsedPort : null;
		if ($host === '' && !empty($server['SERVER_NAME'])) {
			$host = (string)$server['SERVER_NAME'];
		}
		if ($port === null && isset($server['SERVER_PORT']) && is_numeric($server['SERVER_PORT'])) {
			$port = (int)$server['SERVER_PORT'];
		}
		if ($port === null) {
			$port = $scheme === 'https' ? 443 : 80;
		}

		// Build request URI, path, and query pieces.
		$requestUri = (string)($server['REQUEST_URI'] ?? '');
		if ($requestUri === '' && isset($server['ORIG_PATH_INFO'])) {
			$requestUri = (string)$server['ORIG_PATH_INFO'];
			if (!empty($server['QUERY_STRING'])) {
				$requestUri .= '?' . $server['QUERY_STRING'];
			}
		}
		if ($requestUri === '') {
			$requestUri = '/';
		}
		$path = parse_url($requestUri, PHP_URL_PATH);
		if (!is_string($path) || $path === '') {
			$path = '/';
		}
		$query = parse_url($requestUri, PHP_URL_QUERY);
		if ($query === null || $query === false) {
			$query = '';
		}

		$this->urlScheme = $scheme;
		$this->urlHost = $host;
		$this->urlPort = (int)$port;
		$this->urlPath = $path;
		$this->urlQuery = $query;
		$this->requestUri = $path . ($query !== '' ? '?' . $query : '');
		$this->protocol = $server['SERVER_PROTOCOL'] ?? $this->protocol;
		$this->url = $this->getUrlScheme() . '://' . $this->getUrlAuthority() . $this->requestUri;
	}

	public function getParameter(string $name, $default = null)
	{
		// Always-on whitelist enforcement
		// BUT: If parameter doesn't exist in request AND a default is provided, allow it
		if (!$this->isParameterWhitelisted($name)) {
			// Determine whether the parameter exists in either runtime store or the
			// attached PSR-7 request. Runtime parameters must count as existing even
			// when no PSR request is attached (legacy setParameter usage).
			$exists = array_key_exists($name, $this->runtimeParameters);
			if (!$exists && $this->request !== null) {
				$exists = $this->getRequestParam($this->request, $name, null) !== null;
				// Check bracket [] alias against base name as well
				if (!$exists && str_ends_with($name, '[]')) {
					$base = substr($name, 0, -2);
					$exists = $this->getRequestParam($this->request, $base, null) !== null || array_key_exists($base, $this->runtimeParameters);
				}
			}

			// If the parameter exists (in runtime or request) it's an unvalidated
			// access and must throw. If it doesn't exist, we only allow returning
			// the default when the caller explicitly provided one. We detect that
			// via func_num_args(): callers that omitted the default should get an
			// exception to avoid accidental silent masking of missing/unvalidated
			// inputs.
			if ($exists) {
				throw new \Agavi\Exception\AgaviUnvalidatedParameterAccessException('Access to unvalidated parameter "' . $name . '" denied under strict validation.');
			}
			if (func_num_args() > 1) {
				return $default;
			}
			throw new \Agavi\Exception\AgaviUnvalidatedParameterAccessException('Access to unvalidated parameter "' . $name . '" denied under strict validation.');
		}
		// 1. Direct runtime override
		if (array_key_exists($name, $this->runtimeParameters)) {
			return $this->runtimeParameters[$name];
		}
		if ($this->request === null) {
			return $default;
		}
		// 2. Direct intrinsic (flat) lookup through helper
		$value = $this->getRequestParam($this->request, $name, null);
		if ($value !== null) {
			return $value;
		}
		// 3. Bracket strip fallback: if caller used trailing [] treat as base array request
		if (str_ends_with($name, '[]')) {
			$base = substr($name, 0, -2);
			if (array_key_exists($base, $this->runtimeParameters)) {
				return $this->runtimeParameters[$base];
			}
			$baseVal = $this->getRequestParam($this->request, $base, null);
			if ($baseVal !== null) {
				return $baseVal;
			}
		}
		// 4. Legacy path resolution (nested/bracket syntax) over merged parameters (runtime wins)
		$merged = null; $ref = null; $lookupException = null;
		try {
			$merged = $this->runtimeParameters + $this->getRequestParams($this->request, 'parameters');
			$ref = AgaviArrayPathDefinition::getValue($name, $merged, null);
		} catch (\Throwable $e) { $lookupException = $e; }
		if ($ref !== null) {
			return $ref;
		}
		// 4b. Manual bracket path fallback when legacy resolver fails (e.g. data[0][Application])
		if ($merged !== null && str_contains($name, '[')) {
			$manual = $this->resolveBracketPath($name, $merged);
			if ($manual !== null) {
				return $manual;
			}
		}
		return $default;
	}

	// --- PSR-7 MessageInterface required methods (delegating when trait not providing concrete ones) ---
	public function getProtocolVersion(): string
	{
		return $this->request?->getProtocolVersion() ?? '1.1';
	}

	public function withProtocolVersion($version): self
	{
		if ($this->request === null) { throw new \RuntimeException('PSR-7 request not attached'); }
		$next = clone $this; $next->attachPsrRequest($this->request->withProtocolVersion($version)); return $next;
	}

	public function getHeaders(): array
	{
		return $this->request?->getHeaders() ?? [];
	}

	public function hasHeader($name): bool
	{
		return $this->request?->hasHeader($name) ?? false;
	}

	public function hasParameter(string $name): bool
	{
		if(!$this->isParameterWhitelisted($name)) { return false; }
		if (array_key_exists($name, $this->runtimeParameters)) {
			return true;
		}
		if ($this->request === null) {
			return false;
		}
		if ($this->getRequestParam($this->request, $name, null) !== null) {
			return true;
		}
		if (str_ends_with($name, '[]')) {
			$base = substr($name, 0, -2);
			if (array_key_exists($base, $this->runtimeParameters)) {
				return true;
			}
			if ($this->getRequestParam($this->request, $base, null) !== null) {
				return true;
			}
		}
		$merged = null; $has = false; $ex = null;
		try { $merged = $this->runtimeParameters + $this->getRequestParams($this->request, 'parameters'); $has = AgaviArrayPathDefinition::hasValue($name, $merged); } catch (\Throwable $e) { $ex = $e; }
		if ($has) { return true; }
		if ($merged !== null && str_contains($name, '[') && $this->resolveBracketPath($name, $merged) !== null) { return true; }
		if ($ex !== null) { return false; }
		return false;
	}

	/**
	 * Manual, conservative bracket path resolution for nested parameters like foo[0][bar].
	 * Returns null if any segment is missing. Does not support empty brackets [] append semantics for safety.
	 */
	private function resolveBracketPath(string $path, array $rootArray)
	{
		$firstBracket = strpos($path, '[');
		if ($firstBracket === false) {
			return $rootArray[$path] ?? null;
		}
		$rootKey = substr($path, 0, $firstBracket);
		if ($rootKey === '' || !array_key_exists($rootKey, $rootArray)) {
			return null;
		}
		$current = $rootArray[$rootKey];
		if (!is_array($current)) {
			return null;
		}
		if (!preg_match_all('/\[([^\]]*)\]/', $path, $matches)) {
			return null;
		}
		foreach ($matches[1] as $seg) {
			if ($seg === '' || !is_array($current) || !array_key_exists($seg, $current)) {
				return null;
			}
			$current = $current[$seg];
		}
		return $current;
	}

	/**
	 * Retrieve parameters. When $source is null we merge runtime parameters
	 * over intrinsic HTTP parameters. Specific sources bypass runtime store.
	 * Allowed $source values mirror legacy API: parameters|cookies|files|headers|attributes|runtime
	 */
	public function getParameters(?string $source = null)
	{
		if ($source === 'runtime') {
			return $this->runtimeParameters;
		}
		if ($this->request === null) {
			// In test or pre-attachment scenarios, expose runtime parameters also for explicit 'parameters' source
			if ($source === null || $source === 'parameters') {
				return $this->runtimeParameters;
			}
			if ($source === 'files') { return []; }
			return [];
		}
		if ($source === null) {
			// Merge intrinsic HTTP param sources (query+body) then overlay runtime
			$base = $this->getRequestParams($this->request, 'parameters');
			return $this->runtimeParameters + $base; // runtime wins
		}
		if ($source === 'parameters') {
			$base = $this->getRequestParams($this->request, 'parameters');
			// Ensure runtime parameters are also visible through explicit 'parameters' source for legacy validators
			$merged = $this->runtimeParameters + $base;
			/*if (getenv('DEBUG_TESTS') || (defined('DEBUG_TESTS') && DEBUG_TESTS)) {
				try { AgaviDebugLogger::debug('[TestDebug][getParameters.parameters] runtimeKeys=' . implode(',', array_keys($this->runtimeParameters)) . ' baseKeys=' . implode(',', array_keys($base)) . ' mergedKeys=' . implode(',', array_keys($merged))); } catch(\Throwable) {}
			}*/
			return $merged;
		}
		if ($source === 'files') { return $this->request?->getUploadedFiles() ?? []; }
		return $this->getRequestParams($this->request, $source);
	}

	/**
	 * Retrieves all fields of a stored data type (legacy AgaviRequestDataHolder compatibility).
	 *
	 * @param      string The name of the source to operate on.
	 *
	 * @return     array The values.
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function getAll($source)
	{
		return $this->getParameters($source);
	}

	/**
	 * Remove a parameter from runtime store or intrinsic sources.
	 * If $source is null or 'runtime' we only affect runtime store.
	 */
	public function removeParameter(string $name, string $source = 'runtime')
	{
		if ($source === 'runtime' || $source === null) {
			// Support nested path removal for runtime parameters (best-effort)
			if (array_key_exists($name, $this->runtimeParameters)) {
				unset($this->runtimeParameters[$name]);
				return $this;
			}
			try {
				$dummy = &$this->runtimeParameters; // alias for path removal
				AgaviArrayPathDefinition::unsetValue($name, $dummy);
			} catch (\Throwable) {
			}
			return $this;
		}
		if ($this->request !== null) {
			$this->request = $this->withoutParameter($this->request, $name, $source) ?? $this->request;
		}
		return $this;
	}

	/**
	 * Legacy write API: set a runtime parameter (not an attribute, not HTTP input).
	 */
	public function setParameter(string $name, $value): void
	{
		// Support legacy bracket notation when tests or legacy code call setParameter
		if (strpos($name, '[') !== false) {
			$root = substr($name, 0, strpos($name, '['));
			if ($root !== '') {
				if (!array_key_exists($root, $this->runtimeParameters) || !is_array($this->runtimeParameters[$root])) {
					$this->runtimeParameters[$root] = [];
				}
				if (preg_match_all('/\\[([^\\]]*)\\]/', $name, $matches)) {
					$segments = $matches[1];
					$current =& $this->runtimeParameters[$root];
					$last = count($segments) - 1;
					foreach ($segments as $i => $seg) {
						if ($seg === '') { // append semantics
							$seg = (string)count($current);
						}
						if ($i === $last) {
							$current[$seg] = $value;
						} else {
							if (!isset($current[$seg]) || !is_array($current[$seg])) {
								$current[$seg] = [];
							}
							$current =& $current[$seg];
						}
					}
					// Do not additionally store the fully qualified bracket path to avoid duplication
					return;
				}
			}
		}
		$this->runtimeParameters[$name] = $value;
		// NEW: If setting a root array (e.g. data => [[...]]), synthesize bracket keys (data[0][Field])
		if (is_array($value) && $this->shouldMaterializeBracketPaths($name, $value)) {
			$this->materializeBracketPaths($name, $value);
		}
	}

	/**
	 * Decide whether to materialize bracket paths for a root key; avoid huge structures (>200 elements) for performance.
	 */
	private function shouldMaterializeBracketPaths(string $root, array $value): bool
	{
		if ($root === '') { return false; }
		// Basic heuristic: array of arrays (first element is array) and size reasonable
		$first = reset($value);
		return is_array($first) && count($value) <= 200;
	}

	/**
	 * For a structure like ['data' => [ ['Application' => 'orders', 'Enabled' => true] ] ]
	 * create flattened bracketed entries: data[0][Application], data[0][Enabled].
	 * Stored as scalar runtimeParameters so legacy validator key enumeration that scans flat names picks them up.
	 */
	private function materializeBracketPaths(string $root, array $list): void
	{
		foreach ($list as $idx => $row) {
			if (!is_array($row)) { continue; }
			foreach ($row as $k => $v) {
				$flatKey = $root . '[' . $idx . '][' . $k . ']';
				// Do not overwrite if explicitly set already
				if (!array_key_exists($flatKey, $this->runtimeParameters)) {
					$this->runtimeParameters[$flatKey] = $v;
				}
			}
		}
	}

	/**
	 * Legacy append API mirrors AgaviParameterHolder::appendParameter semantics.
	 */
	public function appendParameter(string $name, $value): void
	{
		if (!array_key_exists($name, $this->runtimeParameters) || !is_array($this->runtimeParameters[$name])) {
			if (!array_key_exists($name, $this->runtimeParameters)) {
				$this->runtimeParameters[$name] = [];
			} else {
				$this->runtimeParameters[$name] = (array)$this->runtimeParameters[$name];
			}
		}
		$this->runtimeParameters[$name][] = $value;
	}

	/**
	 * Define the set of validated parameter names. Always-on enforcement.
	 * Calling this replaces the whitelist completely.
	 */
	public function enforceValidatedParameters(array $keys): void
	{
		foreach($keys as $key) {
			if($key === '') {
				continue;
			}
			foreach($this->expandValidatedKeyVariants($key) as $variant) {
				$this->validatedKeys[$variant] = true;
			}
		}
	}

	/**
	 * Expand a validated parameter name to include relevant base aliases.
	 * For example "foo[]" will add both "foo[]" and "foo" to the whitelist.
	 */
	private function expandValidatedKeyVariants(string $key): array
	{
		$variants = [$key => true];
		if(str_contains($key, '[')) {
			try {
				$partsInfo = AgaviArrayPathDefinition::getPartsFromPath($key);
			} catch(\Throwable) {
				return array_keys($variants);
			}
			if(!empty($partsInfo['absolute']) && !empty($partsInfo['parts'])) {
				$root = $partsInfo['parts'][0];
				if($root !== '') {
					$variants[$root] = true;
					$remainder = array_slice($partsInfo['parts'], 1);
					if(isset($remainder[0]) && $remainder[0] === '') {
						$variants[$root . '[]'] = true;
					}
				}
			}
		}
		return array_keys($variants);
	}

	private function isParameterWhitelisted(string $name): bool
	{
		if(isset($this->validatedKeys[$name])) {
			return true;
		}
		$alias = $this->normalizeNumericIndexKey($name);
		if($alias !== null && isset($this->validatedKeys[$alias])) {
			// Memoize alias for faster subsequent checks
			$this->validatedKeys[$name] = true;
			return true;
		}
		return false;
	}

	private function normalizeNumericIndexKey(string $name): ?string
	{
		if(!str_contains($name, '[')) {
			return null;
		}
		try {
			$info = AgaviArrayPathDefinition::getPartsFromPath($name);
		} catch(\Throwable) {
			return null;
		}
		$parts = $info['parts'];
		if(empty($parts)) {
			return null;
		}
		$updated = false;
		$normalizedParts = $parts;
		foreach($normalizedParts as $idx => $segment) {
			if($info['absolute'] && $idx === 0) {
				continue;
			}
			if($segment === '') {
				continue;
			}
			$segmentStr = (string)$segment;
			if($segmentStr !== '' && ctype_digit($segmentStr)) {
				$normalizedParts[$idx] = '';
				$updated = true;
			}
		}
		if(!$updated) {
			return null;
		}
		$root = '';
		$tail = $normalizedParts;
		if($info['absolute']) {
			$root = (string)$normalizedParts[0];
			$tail = array_slice($normalizedParts, 1);
		}
		$result = $root;
		if(!empty($tail)) {
			$result .= '[' . implode('][', $tail) . ']';
		}
		return $result;
	}

	public function clearParameters()
	{
		$this->runtimeParameters = [];

		if ($this->request !== null) {
			$this->request = $this->request->withParsedBody(null)->withQueryParams([])->withCookieParams([]);
			$headers = $this->request->getHeaders();
			foreach ($headers as $headerName => $headerValue) {
				$this->request = $this->request->withoutHeader($headerName);
			}
		}
	}

	/**
	 * Prune request parameters after validation in strict/conditional modes.
	 *
	 * $keep contains names of successfully validated arguments. $failed contains
	 * names of arguments that explicitly failed validation. Everything else in
	 * intrinsic (query+body merged) and runtime parameters is considered
	 * unvalidated and will be removed. Module/action parameters may optionally
	 * be preserved if $preserveModuleAction is true.
	 *
	 * This operates only on the "parameters" source (query+body merged) plus
	 * runtime parameters, matching validator argument semantics. Cookies, files
	 * and headers are left untouched here because validator arguments typically
	 * target parameters; if needed we can later extend with additional source
	 * pruning rules.
	 */
	public function pruneParametersToValidated(array $keep, array $failed, bool $preserveModuleAction, ?string $moduleKey, ?string $actionKey): void
	{
		/*
		 * Security hardening: remove any user-supplied data that was not explicitly validated.
		 * Sources affected: parameters (query/body), cookies, headers, files, and runtime parameters.
		 * Rationale: Prevent injection vectors (SQL, header manipulation, log forgery) from unvalidated input
		 * lingering in the request object after validation passes control to later layers.
		 */
		$keepSet = [];
		foreach($keep as $k) { $keepSet[$k] = true; }
		$failedSet = [];
		foreach($failed as $k) { $failedSet[$k] = true; }
		$preserve = [];
		if($preserveModuleAction) {
			if($moduleKey) { $preserve[$moduleKey] = true; }
			if($actionKey) { $preserve[$actionKey] = true; }
		}

		if($this->request !== null) {
			// Parameters (query + body)
			$query = $this->request->getQueryParams();
			$body = $this->request->getParsedBody();
			if(!is_array($body)) { $body = []; }
			$intrinsic = $body + $query;
			foreach(array_keys($intrinsic) as $name) {
				$remove = true;
				if(isset($keepSet[$name])) { $remove = false; }
				if(isset($failedSet[$name])) { $remove = true; }
				if(isset($preserve[$name])) { $remove = false; }
				if($remove) {
					if(array_key_exists($name, $query)) { unset($query[$name]); }
					if(array_key_exists($name, $body)) { unset($body[$name]); }
				}
			}
			$this->request = $this->request->withQueryParams($query)->withParsedBody($body);

			// (Headers, cookies, files handled in pruneExtendedSources to allow full keep/fail maps per source)
		}

		// Runtime parameters
		foreach(array_keys($this->runtimeParameters) as $rName) {
			$remove = true;
			if(isset($keepSet[$rName])) { $remove = false; }
			if(isset($failedSet[$rName])) { $remove = true; }
			if(isset($preserve[$rName])) { $remove = false; }
			if($remove) { unset($this->runtimeParameters[$rName]); }
		}
	}

	/**
	 * Extended pruning invoked by ValidationManager for non-parameter sources when available.
	 * Each keep/failed array is an associative map of name => true.
	 */
	public function pruneExtendedSources(array $headerKeep, array $headerFail, array $cookieKeep, array $cookieFail, array $fileKeep, array $fileFail): void
	{
		if($this->request === null) { return; }
		// Headers
		foreach(array_keys($this->request->getHeaders()) as $h) {
			$l = strtolower($h);
			$remove = true;
			if(isset($headerKeep[$h]) || isset($headerKeep[$l])) { $remove = false; }
			if(isset($headerFail[$h]) || isset($headerFail[$l])) { $remove = true; }
			if($remove) { $this->request = $this->request->withoutHeader($h); }
		}
		// Cookies
		$cookies = $this->request->getCookieParams();
		foreach(array_keys($cookies) as $c) {
			$remove = true;
			if(isset($cookieKeep[$c])) { $remove = false; }
			if(isset($cookieFail[$c])) { $remove = true; }
			if($remove) { unset($cookies[$c]); }
		}
		$this->request = $this->request->withCookieParams($cookies);
		// Files
		$files = $this->request->getUploadedFiles();
		$changed = false;
		foreach(array_keys($files) as $f) {
			$remove = true;
			if(isset($fileKeep[$f])) { $remove = false; }
			if(isset($fileFail[$f])) { $remove = true; }
			if($remove) { unset($files[$f]); $changed = true; }
		}
		if($changed) { $this->request = $this->request->withUploadedFiles($files); }
	}

	// -----------------------
	// Legacy attribute helpers
	// -----------------------

	/**
	 * Append a value to a list-style attribute (legacy API used by views to add css/js).
	 * Values are stored as array under attribute name. Idempotent for identical consecutive adds.
	 */
	public function appendAttribute(string $name, $value): void
	{
		$current = $this->request?->getAttribute($name);
		if ($current === null) {
			$current = [];
		} elseif (!is_array($current)) {
			$current = [$current];
		}
		$current[] = $value;
		if ($this->request !== null) {
			$this->request = $this->request->withAttribute($name, $current);
		}
	}

	/**
	 * Backwards compat: alias for appendAttribute when code used singular.
	 */
	public function appendListAttribute(string $name, $value): void
	{
		$this->appendAttribute($name, $value);
	}

	/**
	 * Legacy API: check if attribute exists (non-null) on underlying PSR request.
	 */
	public function hasAttribute(string $name): bool
	{
		if ($this->request === null) {
			return false;
		}
		return $this->request->getAttribute($name) !== null;
	}

	/**
	 * Legacy mutator: set attribute (overwrites any existing value).
	 */
	public function setAttribute(string $name, $value): void
	{
		if ($this->request !== null) {
			$this->request = $this->request->withAttribute($name, $value);
		}
	}

	public function getHeader($name): array
	{
		return $this->request?->getHeader($name) ?? [];
	}

	public function getHeaderLine($name): string
	{
		return $this->request?->getHeaderLine($name) ?? '';
	}

	public function withHeader($name, $value): self
	{
		if ($this->request === null) {
			throw new \RuntimeException('PSR-7 request not attached');
		}
		$next = clone $this;
		$next->attachPsrRequest($this->request->withHeader($name, $value));
		return $next;
	}

	public function withAddedHeader($name, $value): self
	{
		if ($this->request === null) {
			throw new \RuntimeException('PSR-7 request not attached');
		}
		$next = clone $this;
		$next->attachPsrRequest($this->request->withAddedHeader($name, $value));
		return $next;
	}

	public function withoutHeader($name): self
	{
		if ($this->request === null) {
			throw new \RuntimeException('PSR-7 request not attached');
		}
		$next = clone $this;
		$next->attachPsrRequest($this->request->withoutHeader($name));
		return $next;
	}

	public function getBody(): StreamInterface
	{
		if ($this->request === null) {
			throw new \RuntimeException('PSR-7 request not attached');
		}
		return $this->request->getBody();
	}

	public function withBody(StreamInterface $body): self
	{
		if ($this->request === null) {
			throw new \RuntimeException('PSR-7 request not attached');
		}
		$next = clone $this;
		$next->attachPsrRequest($this->request->withBody($body));
		return $next;
	}

	public function getRequestTarget(): string
	{
		return $this->request?->getRequestTarget() ?? '';
	}

	public function withRequestTarget($requestTarget): self
	{
		if ($this->request === null) {
			throw new \RuntimeException('PSR-7 request not attached');
		}
		$next = clone $this;
		$next->attachPsrRequest($this->request->withRequestTarget($requestTarget));
		return $next;
	}

	public function getMethod(): string
	{
		return $this->request?->getMethod() ?? ($this->method ?? 'GET');
	}

	public function withMethod($method): self
	{
		if ($this->request === null) {
			throw new \RuntimeException('PSR-7 request not attached');
		}
		$next = clone $this;
		$next->attachPsrRequest($this->request->withMethod($method));
		return $next;
	}

	public function getUri(): UriInterface
	{
		if ($this->request === null) {
			throw new \RuntimeException('PSR-7 request not attached');
		}
		return $this->request->getUri();
	}

	public function withUri(UriInterface $uri, $preserveHost = false): self
	{
		if ($this->request === null) {
			throw new \RuntimeException('PSR-7 request not attached');
		}
		$next = clone $this;
		$next->attachPsrRequest($this->request->withUri($uri, $preserveHost));
		return $next;
	}

	public function getServerParams(): array
	{
		return $this->request?->getServerParams() ?? [];
	}

	public function getCookieParams(): array
	{
		return $this->request?->getCookieParams() ?? [];
	}

	public function withCookieParams(array $cookies): self
	{
		if ($this->request === null) {
			throw new \RuntimeException('PSR-7 request not attached');
		}
		$next = clone $this;
		$next->attachPsrRequest($this->request->withCookieParams($cookies));
		return $next;
	}

	public function getQueryParams(): array
	{
		return $this->request?->getQueryParams() ?? [];
	}

	public function withQueryParams(array $query): self
	{
		if ($this->request === null) {
			throw new \RuntimeException('PSR-7 request not attached');
		}
		$next = clone $this;
		$next->attachPsrRequest($this->request->withQueryParams($query));
		return $next;
	}

	/**
	 * @return array<string, UploadedFileInterface|array>
	 */
	public function getUploadedFiles(): array
	{
		return $this->request?->getUploadedFiles() ?? [];
	}

	public function withUploadedFiles(array $uploadedFiles): self
	{
		if ($this->request === null) {
			throw new \RuntimeException('PSR-7 request not attached');
		}
		$next = clone $this;
		$next->attachPsrRequest($this->request->withUploadedFiles($uploadedFiles));
		// legacy adaptedFiles cache removed; nothing to invalidate
		return $next;
	}

	public function getParsedBody(): mixed
	{
		return $this->request?->getParsedBody();
	}

	public function withParsedBody($data): self
	{
		if ($this->request === null) {
			throw new \RuntimeException('PSR-7 request not attached');
		}
		$next = clone $this;
		$next->attachPsrRequest($this->request->withParsedBody($data));
		return $next;
	}

	public function getAttributes(): array
	{
		return $this->request?->getAttributes() ?? [];
	}

	public function getAttribute($name, $default = null): mixed
	{
		return $this->request?->getAttribute($name, $default);
	}

	public function withAttribute($name, $value): self
	{
		if ($this->request === null) {
			throw new \RuntimeException('PSR-7 request not attached');
		}
		$next = clone $this;
		$next->attachPsrRequest($this->request->withAttribute($name, $value));
		return $next;
	}

	public function withoutAttribute($name): self
	{
		if ($this->request === null) {
			throw new \RuntimeException('PSR-7 request not attached');
		}
		$next = clone $this;
		$next->attachPsrRequest($this->request->withoutAttribute($name));
		return $next;
	}


	/**
	 * Do any necessary startup work after initialization.
	 *
	 * This method is not called directly after initialize().
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function startup()
	{
		// If no PSR-7 request is attached yet, skip legacy superglobal clearing
		if ($this->request === null) {
			return;
		}

		if ($this->request->getAttribute('unset_input', true)) {
			$rla = ini_get('register_long_arrays');

			$_GET = $_POST = $_COOKIE = $_REQUEST = $_FILES = [];

			foreach ($_SERVER as $key => $value) {
				if (str_starts_with($key, 'HTTP_') || $key == 'CONTENT_TYPE' || $key == 'CONTENT_LENGTH') {
					unset($_SERVER[$key]);
					unset($_ENV[$key]);
					if ($rla) {
						unset($GLOBALS['HTTP_SERVER_VARS'][$key]);
						unset($GLOBALS['HTTP_ENV_VARS'][$key]);
					}
				}
			}
		}
	}

	/**
	 * Reset web request state for FrankenPHP worker compatibility.
	 * Clears web-specific request properties that could leak between requests.
	 *
	 * @author     Generated for FrankenPHP worker compatibility
	 * @since      1.1.0
	 */
	public function reset(): void
	{
		$this->request = null;
		$this->runtimeParameters = [];
		$this->protocol = null;
		$this->urlScheme = $this->urlHost = $this->urlPath = $this->urlQuery = $this->requestUri = $this->url = '';
		$this->urlPort = 0;
	}
}
