<?php
namespace Quiote\Request;

use Quiote\Context;
use Quiote\Util\ArrayPathDefinition;
use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * WebRequest provides additional support for web-only client requests
 * such as cookie and file manipulation.
 *
 * WebRequest is fully immutable: every mutator (setParameter, appendParameter,
 * removeParameter, declareParameter(s), enforceValidatedParameters,
 * clearParameters, setAttribute, appendAttribute, and every inherited PSR-7
 * with*() method) returns a NEW WebRequest instance. Callers must capture and
 * propagate the return value; a discarded return value is a no-op, not a bug
 * in WebRequest.
 *
 * Composes a Nyholm\Psr7\ServerRequest to implement PSR-7 rather than extending
 * it: Nyholm marks its request classes @final, and composition also means we
 * are never at the mercy of a future Nyholm release changing its with*()
 * methods away from clone-based immutability.
 */
class WebRequest implements ServerRequestInterface, ResetInterface
{
	use Psr7DelegationTrait;
	use Psr7RequestTrait;
	use RequestInspectionTrait;
	use UploadedFileAccessTrait;

	private RequestUrl $url;

	private RequestParameterStore $params;

	/**
	 * @param      string $method
	 * @param      string|UriInterface|null $uri
	 * @param      array<string, string|string[]> $headers
	 * @param      string|resource|\Psr\Http\Message\StreamInterface|null $body
	 * @param      string $version
	 * @param      array<string, mixed> $serverParams
	 */
	public function __construct(
		string $method = 'GET',
		$uri = null,
		array $headers = [],
		$body = null,
		string $version = '1.1',
		array $serverParams = []
	) {
		$this->psrRequest = new \Nyholm\Psr7\ServerRequest(
			$method,
			$uri ?? new \Quiote\Http\SimpleUri('http://localhost/'),
			$headers,
			$body,
			$version,
			$serverParams
		);

		$this->params = new RequestParameterStore();
		$this->url = RequestUrl::fromUri($this->psrRequest->getUri(), $this->psrRequest->getServerParams(), $this->psrRequest->getProtocolVersion());
	}

	/**
	 * Build a WebRequest carrying the state of an arbitrary PSR-7 request.
	 * WebRequest wraps a Nyholm\Psr7\ServerRequest internally, but a plain
	 * Nyholm\Psr7\ServerRequest can still flow through the pipeline (it lacks the
	 * Quiote helpers such as isHttps()/getParameter()). This adapter produces a
	 * WebRequest with the same method, URI, headers, body, protocol, server
	 * params, cookies, query params, uploaded files, parsed body and attributes,
	 * so the framework can always rely on getRequest() returning a WebRequest.
	 * @return     self The same instance if it is already a WebRequest, else a copy.
	 */
	public static function fromPsr(ServerRequestInterface $request): self
	{
		if ($request instanceof self) {
			return $request;
		}

		$new = new self(
			$request->getMethod(),
			$request->getUri(),
			$request->getHeaders(),
			$request->getBody(),
			$request->getProtocolVersion(),
			$request->getServerParams()
		);

		$new = $new
			->withCookieParams($request->getCookieParams())
			->withQueryParams($request->getQueryParams())
			->withUploadedFiles($request->getUploadedFiles());

		$parsedBody = $request->getParsedBody();
		if ($parsedBody !== null) {
			$new = $new->withParsedBody($parsedBody);
		}

		foreach ($request->getAttributes() as $name => $value) {
			$new = $new->withAttribute($name, $value);
		}

		return $new;
	}

	/**
	 * Initialize this Request (compat stub for factories.xml flow). Mutates
	 * this instance in place (matching the constructor's own direct property
	 * writes) rather than returning a new instance: this runs once, right
	 * after construction, before the request starts flowing through the
	 * pipeline as an immutable value.
	 * @param      array<string, mixed> $parameters
	 */
	public function initialize(Context $context, array $parameters = []): void
	{
		// Fallback for legacy flows: when not constructed with proper params
		// need URL metadata derived from superglobals for helpers/tests.
		$bootstrapped = RequestUrl::fromServerParams($_SERVER);
		if ($bootstrapped->protocol === null && $this->url->protocol !== null) {
			$bootstrapped = $bootstrapped->withProtocol($this->url->protocol);
		}
		$this->url = $bootstrapped;

		$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
		$imported = JsonBodyIngestor::ingest(
			$this->getMethod(),
			is_string($contentType) ? $contentType : '',
			fn (): ?UploadedFileInterface => $this->getUploadedFile('put_file'),
			static fn (): string => (string)file_get_contents('php://input'),
		);
		foreach ($imported as $key => $value) {
			$param = is_string($key) ? $key : (string)$key;
			$this->params = $this->params->withParameter($param, $value);
		}
	}

	#[\Deprecated(message: 'No longer needed - WebRequest IS the PSR-7 request')]
	public function attachPsrRequest(ServerRequestInterface $request): void
	{
		// No-op for backward compatibility
		trigger_error('attachPsrRequest() is deprecated - WebRequest wraps a ServerRequest directly', E_USER_DEPRECATED);
	}

	// -----------------------
	// URL metadata
	// -----------------------

	public function getProtocol(): ?string
	{
		return $this->url->protocol;
	}

	public function getUrlScheme(): string
	{
		return $this->url->scheme;
	}

	public function getUrlHost(): string
	{
		return $this->url->host;
	}

	public function getUrlPort(): int
	{
		return $this->url->effectivePort();
	}

	/**
	 * @param      bool $forcePort Whether or not ports 80 (for HTTP) and 443 (for HTTPS)
	 *                  should be included in the return string.
	 */
	public function getUrlAuthority(bool $forcePort = false): string
	{
		return $this->url->authority($forcePort);
	}

	public function getRequestUri(): string
	{
		return $this->url->requestUri;
	}

	public function getUrlPath(): string
	{
		return $this->url->path;
	}

	public function getUrlQuery(): string
	{
		return $this->url->query;
	}

	/**
	 * Retrieve the full request URL, including protocol, server name, port (if
	 * necessary), and request URI. Recomputed dynamically rather than cached,
	 * so it always reflects any setUrlScheme()/setUrlHost()/etc. call made
	 * after construction.
	 */
	public function getUrl(): string
	{
		return
			$this->getUrlScheme() . '://' .
			$this->getUrlAuthority() .
			$this->getRequestUri();
	}

	public function isHttps(): bool
	{
		return $this->url->isHttps();
	}

	/**
	 * @param      string $scheme
	 */
	public function setUrlScheme($scheme): void
	{
		$this->url = $this->url->withScheme($scheme);
	}

	/**
	 * @param      string $host
	 */
	public function setUrlHost($host): void
	{
		$this->url = $this->url->withHost($host);
	}

	/**
	 * @param      int $port
	 */
	public function setUrlPort($port): void
	{
		$this->url = $this->url->withPort((int)$port);
	}

	/**
	 * @param      string $uri
	 */
	public function setRequestUri($uri): void
	{
		$this->url = $this->url->withRequestUri($uri);
	}

	/**
	 * @param      string $urlPath
	 */
	public function setUrlPath($urlPath): void
	{
		$this->url = $this->url->withPath($urlPath);
	}

	/**
	 * @param      string $urlQuery
	 */
	public function setUrlQuery($urlQuery): void
	{
		$this->url = $this->url->withQuery($urlQuery);
	}

	/**
	 * @param      ?string $protocol
	 */
	public function setProtocol($protocol): void
	{
		$this->url = $this->url->withProtocol($protocol);
	}

	#[\Override]
	public function withUri(UriInterface $uri, $preserveHost = false): static
	{
		$new = $this->withPsrRequest($this->psrRequest->withUri($uri, $preserveHost));
		$new->url = RequestUrl::fromUri($uri, $new->getServerParams(), $new->getProtocolVersion());
		return $new;
	}

	// -----------------------
	// Runtime parameters / strict validation whitelist
	// -----------------------

	/**
	 * Strict whitelist enforcement. A parameter is whitelisted iff it was
	 * declared by a validator in validators.xml (seeded via
	 * declareParameters() at config parse time) or explicitly set via
	 * setParameter() from application code.
	 *
	 * When called WITHOUT a default (getParameter('foo')): accessing an
	 * unvalidated parameter throws — no escape hatch, catches dev errors.
	 * When called WITH a default (getParameter('foo', null)): the default
	 * is returned silently. The caller has signalled they expect the
	 * parameter may be absent; raw unvalidated HTTP input is never leaked.
	 */
	public function getParameter(string $name, mixed ...$args): mixed
	{
		$hasDefault = !empty($args);
		$default = $hasDefault ? $args[0] : null;
		if (!$this->params->isWhitelisted($name)) {
			if (!$hasDefault) {
				throw new \Quiote\Exception\UnvalidatedParameterAccessException('Access to unvalidated parameter "' . $name . '" denied under strict validation.');
			}
			return $default;
		}
		// 1. Direct runtime override
		if ($this->params->has($name)) {
			return $this->params->get($name);
		}
		// 2. Direct intrinsic (flat) lookup through helper
		$value = $this->getRequestParam($this, $name, null);
		if ($value !== null) {
			return $value;
		}
		// 3. Bracket strip fallback: if caller used trailing [] treat as base array request
		if (str_ends_with($name, '[]')) {
			$base = substr($name, 0, -2);
			if ($this->params->has($base)) {
				return $this->params->get($base);
			}
			$baseVal = $this->getRequestParam($this, $base, null);
			if ($baseVal !== null) {
				return $baseVal;
			}
		}
		// 4. Legacy path resolution (nested/bracket syntax) over merged parameters (runtime wins)
		$merged = null; $ref = null;
		try {
			$merged = $this->params->all() + $this->getRequestParams($this, 'parameters');
			$ref = ArrayPathDefinition::getValue($name, $merged, null);
		} catch (\Throwable) { }
		if ($ref !== null) {
			return $ref;
		}
		// 4b. Manual bracket path fallback when legacy resolver fails (e.g. data[0][Application])
		if ($merged !== null && str_contains($name, '[')) {
			$manual = BracketPath::resolve($name, $merged);
			if ($manual !== null) {
				return $manual;
			}
		}
		return $default;
	}

	public function hasParameter(string $name): bool
	{
		if (!$this->params->isWhitelisted($name)) {
			return false;
		}
		if ($this->params->has($name)) {
			return true;
		}
		if ($this->getRequestParam($this, $name, null) !== null) {
			return true;
		}
		if (str_ends_with($name, '[]')) {
			$base = substr($name, 0, -2);
			if ($this->params->has($base)) {
				return true;
			}
			if ($this->getRequestParam($this, $base, null) !== null) {
				return true;
			}
		}
		$merged = null; $has = false;
		try {
			$merged = $this->params->all() + $this->getRequestParams($this, 'parameters');
			$has = ArrayPathDefinition::hasValue($name, $merged);
		} catch (\Throwable) { }
		if ($has) {
			return true;
		}
		return $merged !== null && str_contains($name, '[') && BracketPath::resolve($name, $merged) !== null;
	}

	/**
	 * Retrieve parameters. When $source is null we merge runtime parameters
	 * over intrinsic HTTP parameters. Specific sources bypass runtime store.
	 * Allowed $source values mirror legacy API: parameters|cookies|files|headers|attributes|runtime
	 * @return     array<array-key, mixed>
	 */
	public function getParameters(?string $source = null): array
	{
		if ($source === 'runtime') {
			return $this->params->all();
		}
		if ($source === null || $source === 'parameters') {
			$base = $this->getRequestParams($this, 'parameters');
			return $this->params->all() + $base; // runtime wins
		}
		if ($source === 'files') {
			return $this->psrRequest->getUploadedFiles();
		}
		return $this->getRequestParams($this, $source);
	}

	/**
	 * Retrieves all fields of a stored data type (legacy RequestDataHolder compatibility).
	 * @return     array<array-key, mixed> The values.
	 */
	public function getAll(?string $source): array
	{
		return $this->getParameters($source);
	}

	/**
	 * Remove a parameter from runtime store or intrinsic sources.
	 * If $source is null or 'runtime' we only affect runtime store.
	 */
	public function removeParameter(string $name, string $source = 'runtime'): static
	{
		if ($source === 'runtime') {
			$new = clone $this;
			$new->params = $this->params->withRemovedParameter($name);
			return $new;
		}

		if ($source === 'parameters') {
			$query = $this->getQueryParams();
			$body = $this->getParsedBody();
			unset($query[$name]);
			if (is_array($body)) {
				unset($body[$name]);
			}
			return $this->withQueryParams($query)->withParsedBody($body);
		}

		return $this;
	}

	/**
	 * Legacy write API: set a runtime parameter (not an attribute, not HTTP input).
	 */
	public function setParameter(string $name, mixed $value): static
	{
		$new = clone $this;
		$new->params = $this->params->withParameter($name, $value);
		return $new;
	}

	/**
	 * Mark the given request parameter names as declared (whitelisted for
	 * strict-validation access). Called by the compiled validators.xml config
	 * artifact before any validator is instantiated, so that declared
	 * parameters are accessible even in error views where validation aborts
	 * or never fires.
	 * @param string[] $names Flat list of parameter names (bracket paths
	 *                       allowed, e.g. 'data[0][Title]').
	 */
	public function declareParameters(array $names): static
	{
		$new = clone $this;
		$new->params = $this->params->withDeclaredParameters($names);
		return $new;
	}

	/**
	 * Declare a single parameter name at runtime. Intended for code that adds
	 * validators dynamically via ValidationManager::addChild() outside
	 * the compiled validators.xml path.
	 */
	public function declareParameter(string $name): static
	{
		$new = clone $this;
		$new->params = $this->params->withDeclaredParameter($name);
		return $new;
	}

	/**
	 * Legacy append API mirrors ParameterHolder::appendParameter semantics.
	 */
	public function appendParameter(string $name, mixed $value): static
	{
		$new = clone $this;
		$new->params = $this->params->withAppendedParameter($name, $value);
		return $new;
	}

	/**
	 * @return     array<int, string>
	 */
	public function getRuntimeParameterKeys(): array
	{
		return $this->params->keys();
	}

	/**
	 * Define the set of validated parameter names. Always-on enforcement.
	 * Merges into the existing whitelist.
	 * @param      array<int, string> $keys
	 */
	public function enforceValidatedParameters(array $keys): static
	{
		$new = clone $this;
		$new->params = $this->params->withEnforcedValidatedParameters($keys);
		return $new;
	}

	public function clearParameters(): static
	{
		$new = $this
			->withQueryParams([])
			->withParsedBody([]);
		$new->params = $this->params->withCleared();
		return $new;
	}

	/**
	 * Prune request parameters after validation in strict/conditional modes.
	 * $keep contains names of successfully validated arguments. $failed contains
	 * names of arguments that explicitly failed validation. Everything else in
	 * intrinsic (query+body merged) and runtime parameters is considered
	 * unvalidated and will be removed. Module/action parameters may optionally
	 * be preserved if $preserveModuleAction is true.
	 * This operates only on the "parameters" source (query+body merged) plus
	 * runtime parameters, matching validator argument semantics. Cookies, files
	 * and headers are left untouched here because validator arguments typically
	 * target parameters; pruneExtendedSources() covers those.
	 * @param array<int, string> $keep
	 * @param array<int, string> $failed
	 * @return static New immutable instance with pruned parameters
	 */
	public function pruneParametersToValidated(array $keep, array $failed, bool $preserveModuleAction, ?string $moduleKey, ?string $actionKey): static
	{
		$preserve = [];
		if ($preserveModuleAction) {
			if ($moduleKey) { $preserve[$moduleKey] = true; }
			if ($actionKey) { $preserve[$actionKey] = true; }
		}

		$keepSet = [];
		foreach ($keep as $k) {
			$keepSet[$k] = true;
			// When a bracket-path like "Foo[Bar]" is validated, the root key "Foo"
			// must also be kept so nested arrays survive pruning.
			$firstBracket = strpos($k, '[');
			if ($firstBracket !== false) {
				$root = substr($k, 0, $firstBracket);
				if ($root !== '') {
					$keepSet[$root] = true;
				}
			}
		}
		$failedSet = array_fill_keys($failed, true);

		$query = $this->getQueryParams();
		$body = $this->getParsedBody();
		if (!is_array($body)) {
			$body = [];
		}

		$intrinsic = $body + $query;
		foreach (array_keys($intrinsic) as $name) {
			$remove = true;
			if (isset($keepSet[$name])) { $remove = false; }
			if (isset($failedSet[$name])) { $remove = true; }
			if (isset($preserve[$name])) { $remove = false; }
			if ($remove) {
				unset($query[$name], $body[$name]);
			}
		}

		$new = $this->withQueryParams($query)->withParsedBody($body);
		$new->params = $this->params->pruneTo($keep, $failed, $preserve);
		return $new;
	}

	/**
	 * Extended pruning invoked by ValidationManager for non-parameter sources when available.
	 * Each keep/failed array is an associative map of name => true.
	 * @param array<string, bool> $headerKeep
	 * @param array<string, bool> $headerFail
	 * @param array<string, bool> $cookieKeep
	 * @param array<string, bool> $cookieFail
	 * @param array<string, bool> $fileKeep
	 * @param array<string, bool> $fileFail
	 */
	public function pruneExtendedSources(array $headerKeep, array $headerFail, array $cookieKeep, array $cookieFail, array $fileKeep, array $fileFail): static
	{
		$new = $this;

		$headers = $new->getHeaders();
		foreach (array_keys($headers) as $h) {
			$l = strtolower((string)$h);
			$remove = true;
			if (isset($headerKeep[$h]) || isset($headerKeep[$l])) { $remove = false; }
			if (isset($headerFail[$h]) || isset($headerFail[$l])) { $remove = true; }
			if ($remove) {
				$new = $new->withoutHeader($h);
			}
		}

		$cookies = $new->getCookieParams();
		foreach (array_keys($cookies) as $c) {
			$remove = true;
			if (isset($cookieKeep[$c])) { $remove = false; }
			if (isset($cookieFail[$c])) { $remove = true; }
			if ($remove) { unset($cookies[$c]); }
		}
		$new = $new->withCookieParams($cookies);

		$files = $new->getUploadedFiles();
		foreach (array_keys($files) as $f) {
			$remove = true;
			if (isset($fileKeep[$f])) { $remove = false; }
			if (isset($fileFail[$f])) { $remove = true; }
			if ($remove) { unset($files[$f]); }
		}
		$new = $new->withUploadedFiles($files);

		return $new;
	}

	// -----------------------
	// Legacy attribute helpers (thin wrappers over PSR-7 attributes)
	// -----------------------

	/**
	 * Append a value to a list-style attribute (legacy API used by views to add css/js).
	 * Values are stored as array under attribute name.
	 */
	public function appendAttribute(string $name, mixed $value): static
	{
		$current = $this->getAttribute($name);
		if ($current === null) {
			$current = [];
		} elseif (!is_array($current)) {
			$current = [$current];
		}
		$current[] = $value;
		return $this->withAttribute($name, $current);
	}

	/**
	 * Backwards compat: alias for appendAttribute when code used singular.
	 */
	public function appendListAttribute(string $name, mixed $value): static
	{
		return $this->appendAttribute($name, $value);
	}

	/**
	 * Legacy API: check if attribute exists (non-null).
	 */
	public function hasAttribute(string $name): bool
	{
		return $this->getAttribute($name) !== null;
	}

	/**
	 * Legacy mutator: set attribute (overwrites any existing value).
	 */
	public function setAttribute(string $name, mixed $value): static
	{
		return $this->withAttribute($name, $value);
	}

	/**
	 * Do any necessary startup work after initialization.
	 * This method is not called directly after initialize().
	 */
	public function startup(): void
	{
		// WebRequest IS the PSR-7 request now
		if ($this->psrRequest->getAttribute('unset_input', true)) {
			// register_long_arrays (and $HTTP_*_VARS) was removed in PHP 5.4; ini_get()
			// always reports it disabled on PHP 8.5, so that branch is permanently dead.
			$_GET = $_POST = $_COOKIE = $_REQUEST = $_FILES = [];

			foreach ($_SERVER as $key => $value) {
				if (str_starts_with((string)$key, 'HTTP_') || $key == 'CONTENT_TYPE' || $key == 'CONTENT_LENGTH') {
					unset($_SERVER[$key]);
					unset($_ENV[$key]);
				}
			}
		}
	}

	/**
	 * Reset web request state for FrankenPHP worker compatibility.
	 * Clears web-specific request properties that could leak between requests.
	 */
	public function reset(): void
	{
		// The wrapped PSR-7 request instance itself is immutable and gets
		// replaced wholesale on the next request; only Quiote-specific mutable
		// state needs clearing here.
		$this->params = new RequestParameterStore();
		$this->url = new RequestUrl();
	}
}
