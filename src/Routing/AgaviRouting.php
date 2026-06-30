<?php

declare(strict_types=1);

namespace Agavi\Routing;

use Agavi\AgaviContext;
use Agavi\Exception\AgaviException;
use Agavi\Util\AgaviToolkit;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Route;

abstract class AgaviRouting
{
	private RouteCollection $routes;
	/** @var array<string,array{gen_path:string,cut:bool,path:string,opt:array}> */
	private array $meta = [];
	private ?UrlMatcher $matcher;
	// Symfony routing request context (renamed to avoid collision with AgaviContext)
	private readonly RequestContext $requestContext;
	// Application context (Agavi framework) – exposed to subclasses as $this->context for legacy compatibility
	protected ?AgaviContext $context = null;
	// Compatibility shims / state
	protected string $input = '';
	protected array $inputParameters = [];
	protected array $legacyGenerated = [];
	protected bool $initialized = false;
	protected bool $started = false;
	protected array $sources = [];
	protected array $parameters = [];

	public function __construct(?RequestContext $requestContext = null)
	{
		[$routes, $meta] = $this->build();
		$this->routes = $routes;
		$this->meta = $meta;
		$this->requestContext = $requestContext ?? new RequestContext();
		$this->matcher = new UrlMatcher($this->routes, $this->requestContext);
	}

    abstract protected function build(): array; // [RouteCollection, meta]

    public function match(string $path): array
    {
        if ($this->matcher === null) {
            $this->matcher = new UrlMatcher($this->routes, $this->requestContext);
        }
        return $this->matcher->match($path);
    }

	/**
	 * Import an entire RouteCollection + meta array, replacing current state.
	 * Accepts either the tuple [RouteCollection, meta] or the legacy serialized
	 * form returned by AgaviRoutingConfigHandler (which already supplies those
	 * two elements after unserialize()).
	 * @param array $spec
	 */
	public function importRoutes(array $spec): void
	{
		if (isset($spec[0]) && $spec[0] instanceof RouteCollection) {
			[$routes, $meta] = $spec;
			$this->routes = $routes;
			$this->meta = $meta;
			$this->matcher = new UrlMatcher($this->routes, $this->requestContext);
		} else {
			// Defensive: ignore invalid import silently to avoid fatal during early bootstrap
		}
	}

	/**
	 * Export current routing definition (RouteCollection + meta) for config caching.
	 * Signature kept compatible with AgaviRoutingConfigHandler expectations.
	 * @return array{0:RouteCollection,1:array}
	 */
	public function exportRoutes(): array
	{
		return [$this->routes, $this->meta];
	}

	/**
	 * Add a route dynamically. Supports parent concatenation similar to the
	 * legacy hierarchy model: child segments (without leading slash) are
	 * appended to the parent's pattern. A child pattern beginning with '/' is
	 * treated as absolute while still recording parent linkage.
	 *
	 * @param string $pattern Raw pattern (may be relative if parent provided)
	 * @param array $opts Route options: name (optional), module, action, defaults[] etc.
	 * @param string|null $parent Parent route name for hierarchy.
	 * @return string Final route name.
	 * @throws AgaviException on conflicting duplicate name with different parent.
	 */
	public function addRoute(string $pattern, array $opts = [], ?string $parent = null): string
	{
		$name = $opts['name'] ?? ('r' . (count($this->meta) + 1));
		if (isset($this->meta[$name])) {
			$existingParent = $this->meta[$name]['opt']['parent'] ?? null;
			if ($existingParent !== $parent) {
				throw new AgaviException("Route name '$name' already exists with different parent");
			}
		}

		$parentPattern = null;
		if ($parent !== null) {
			$parentMeta = $this->meta[$parent] ?? null;
			if ($parentMeta) {
				$parentPattern = $parentMeta['pattern'] ?? ($parentMeta['path'] ?? null);
			}
		}

		if ($parentPattern !== null && $pattern !== '' && $pattern[0] !== '/') {
			$joined = rtrim($parentPattern, '/') . '/' . ltrim($pattern, '/');
		} else {
			$joined = $pattern;
		}
		if ($joined === '') { $joined = '/'; }

		// Collect defaults (module/action + explicit defaults key)
		$defaults = $opts['defaults'] ?? [];
		foreach (['module','action','locale','output_type'] as $k) {
			if (isset($opts[$k]) && !isset($defaults[$k])) { $defaults[$k] = $opts[$k]; }
		}

		// Register/update Symfony Route
		$route = new Route($joined, $defaults);
		$this->routes->add($name, $route); // add() overwrites if name exists

		$this->meta[$name] = [
			'gen_path' => $joined,
			'cut' => (bool)($opts['cut'] ?? false),
			'path' => $joined,
			'pattern' => $joined, // convenience for tests expecting 'pattern'
			'match_full' => '#^' . trim($joined, '^') . '$#',
			'match_partial' => '#^' . trim($joined, '^') . '#',
			'opt' => [
				'parent' => $parent,
				'action' => $defaults['action'] ?? null,
			],
		];

		// Invalidate matcher — it will be lazily rebuilt on next match()
		$this->matcher = null;
		return $name;
	}

	/** Retrieve a single route meta entry or null. */
	public function getRoute(string $name): ?array
	{
		return $this->meta[$name] ?? null;
	}

	/**
	 * URL generation.
	 * Always returns a string path. 
	 */
	public function gen($route, array $params = [], $options = [])
	{
		// Support star-suffix refill flag (placeholder – no refill logic yet)
		if (is_string($route) && str_ends_with($route, '*')) {
			$options['refill_all_parameters'] = true;
			$route = substr($route, 0, -1);
		}
		if ($route === null) {
			$script = $_SERVER['SCRIPT_NAME'] ?? '';
			if ($script && $script[0] !== '/') { $script = '/' . $script; }
			$inputPath = $this->input ?: ($this->requestContext->getPathInfo() ?: '/');
			if ($inputPath === '') { $inputPath = '/'; }
			if ($script && str_starts_with($inputPath, (string) $script)) { $path = $inputPath; }
			else { $path = rtrim((string) $script, '/') . ($inputPath === '/' ? '' : $inputPath); if ($path === '') { $path = '/'; } }
			$current = [];
			foreach ($params as $k => $v) { if ($v !== null) { $current[$k] = $v; } }
			$qs = http_build_query($current, '', '&');
			return $path . ($qs ? ('?' . $qs) : '');
		}
		if (!isset($this->meta[$route])) { throw new \InvalidArgumentException("Unknown route '$route'"); }
		$genPath = $this->meta[$route]['gen_path'];
		$symRoute = $this->routes->get($route);
		$defaults = $symRoute ? $symRoute->getDefaults() : [];
		foreach ($params as $k => $v) { if ($v === 'null') $params[$k] = null; elseif ($v === 'remove') unset($params[$k]); }
		$genPath = preg_replace_callback('#\{([a-zA-Z_][a-zA-Z0-9_-]*)(?::[^}]*)?\}#', function ($m) use ($params, $defaults) {
			$p = $m[1];
			$hasParam = array_key_exists($p, $params);
			$hasDefault = array_key_exists($p, $defaults);
			if (!$hasParam && !$hasDefault) { return ''; }
			$val = $hasParam ? $params[$p] : $defaults[$p];
			if ($val === null || $val === '') { return ''; }
			$enc = rawurlencode((string)$val);
			return str_replace('%21', '!', $enc);
		}, $genPath);
		$genPath = preg_replace('#//+#', '/', (string) $genPath) ?? $genPath;
		$genPath = rtrim((string) $genPath, '/');
		if ($genPath === '') $genPath = '/';
		if ($genPath[0] !== '/') $genPath = '/' . $genPath;
		if (($options['omit_defaults'] ?? false) && $symRoute) {
			$genPath = $this->applyOmitDefaultsPruning($route, $genPath, $params, $defaults);
		}
		return $genPath;
	}

	public function genSelf(?string $routeName, array $params = [], array $currentQuery = []): string
	{
		if ($routeName !== null) {
			$r = $this->gen($routeName, $params);
			return is_array($r) ? $r[0] : $r;
		}
		// Mirror null-route generation logic in gen()
		$script = $_SERVER['SCRIPT_NAME'] ?? '';
		if ($script && $script[0] !== '/') {
			$script = '/' . $script;
		}
		$inputPath = $this->input ?: ($this->requestContext->getPathInfo() ?: '/');
		if ($inputPath === '') {
			$inputPath = '/';
		}
		if ($script && str_starts_with($inputPath, (string) $script)) {
			$path = $inputPath;
		} else {
			$path = rtrim((string) $script, '/') . ($inputPath === '/' ? '' : $inputPath);
			if ($path === '') {
				$path = '/';
			}
		}
		$query = $currentQuery;
		foreach ($params as $k => $v) {
			if ($v === null) unset($query[$k]);
			else $query[$k] = $v;
		}
		$qs = http_build_query($query, '', '&');
		return $path . ($qs ? ('?' . $qs) : '');
	}

	public function getRouteCollection(): RouteCollection
	{
		return $this->routes;
	}
	public function getMeta(): array
	{
		return $this->meta;
	}
	public function getBasePath(): string
	{
		return '/';
	}
	/**
	 * Return the absolute origin (scheme://host[:port]) without trailing slash.
	 * Historically this returned just '/', but modern usage (templates, redirects)
	 * expects a fully qualified origin for constructing absolute URLs.
	 */
	public function getBaseHref(): string
	{
		// Prefer data from the Agavi web request if available
		if ($this->context && method_exists($this->context, 'getRequest')) {
			try {
				$rq = $this->context->getRequest();
				if ($rq instanceof \Agavi\Request\AgaviWebRequest) {
					$scheme = $rq->getUrlScheme();
					$auth = $rq->getUrlAuthority();
					if ($auth) {
						return rtrim($scheme . '://' . $auth, '/');
					}
				}
			} catch (\Throwable) { /* fall back to server vars */
			}
		}
		$server = $_SERVER;
		$scheme = $this->normaliseScheme($this->firstHeaderToken($this->resolveForwardedServerValue($server, ['HTTP_X_FORWARDED_PROTO'], 'proto')));
		[$host, $port, $explicitPort] = $this->parseHostAndPortHeader($this->resolveForwardedServerValue($server, ['HTTP_X_ORIGINAL_HOST', 'HTTP_X_FORWARDED_HOST'], 'host'));
		$forwardedPortRaw = $this->resolveForwardedServerValue($server, ['HTTP_X_FORWARDED_PORT'], 'port');
		if ($port === null && $forwardedPortRaw !== null) {
			$token = $this->firstHeaderToken($forwardedPortRaw);
			if ($token !== null && $token !== '' && is_numeric($token)) {
				$port = (int) $token;
				$explicitPort = true;
			}
		}

		if ($scheme === null || $scheme === '') {
			$scheme = $this->detectSchemeFromServer($server);
		}

		if ($host === null || $host === '') {
			[$fallbackHost, $fallbackPort, $fallbackExplicit] = $this->parseHostAndPortHeader($server['HTTP_HOST'] ?? null);
			if ($fallbackHost !== null && $fallbackHost !== '') {
				$host = $fallbackHost;
				if ($port === null && $fallbackPort !== null) {
					$port = $fallbackPort;
					$explicitPort = $explicitPort || $fallbackExplicit;
				}
			} elseif (!empty($server['SERVER_NAME'])) {
				$host = (string) $server['SERVER_NAME'];
			} elseif (!empty($server['SERVER_ADDR'])) {
				$host = (string) $server['SERVER_ADDR'];
			} else {
				$host = 'localhost';
			}
		}

		if ($port === null && !empty($server['SERVER_PORT']) && is_numeric((string) $server['SERVER_PORT'])) {
			$port = (int) $server['SERVER_PORT'];
		}

		$scheme = $scheme ?: 'http';
		$authority = $this->buildAuthority($host, $port, $scheme, $explicitPort);
		return rtrim($scheme . '://' . $authority, '/');
	}
    public function getRequestContext(): RequestContext
    {
        return $this->requestContext;
    }

	// Placeholder for removed legacy features used in some tests; provide no-op minimal parser
	public function parseRouteString(string $routeString): array
	{
		// Simplified token extraction for legacy tests: Keep regex wrapper separate from token scan.
		$pattern = '#' . preg_quote($routeString, '#') . '#';
		$vars = [];
		if (preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_-]*)\}/', $routeString, $m)) {
			foreach ($m[1] as $v) {
				$vars[$v] = [
					'name' => $v,
					'pre' => '',
					'val' => '',
					'post' => '',
					'is_optional' => false
				];
			}
		}
		return [$pattern, $routeString, $vars, 0];
	}

	/** Omit-defaults pruning helper (refactored from inline logic). */
	private function applyOmitDefaultsPruning(string $routeName, string $genPath, array $params, array $defaults): string
	{
		$pattern = $this->meta[$routeName]['gen_path'] ?? '';
		$patternSegments = ($t = trim($pattern, '/')) === '' ? [] : explode('/', $t);
		$segments = explode('/', ltrim($genPath, '/'));
		if (!$patternSegments) return $genPath;
		$phs = [];
		foreach ($patternSegments as $seg) {
			if (preg_match('#^\{([a-zA-Z_][a-zA-Z0-9_-]*)(?::[^}]*)?\}$#', $seg, $m)) {
				$n = $m[1];
				$val = $params[$n] ?? ($defaults[$n] ?? null);
				$val = $val === null ? null : (string)$val;
				$enc = ($val !== null && $val !== '') ? str_replace('%21', '!', rawurlencode($val)) : null;
				$phs[] = ['name' => $n, 'default' => isset($defaults[$n]) ? (string)$defaults[$n] : null, 'used' => $val, 'present' => $enc !== null && $enc !== '', 'index' => null];
			}
		}
		$idx = 0;
		foreach ($patternSegments as $seg) {
			if ($idx >= count($segments)) break;
			if (preg_match('#^\{([a-zA-Z_][a-zA-Z0-9_-]*)(?::[^}]*)?\}$#', $seg, $m)) {
				foreach ($phs as &$p) { if ($p['name'] === $m[1] && $p['index'] === null) { if ($p['present']) $p['index'] = $idx++; break; } }
				unset($p);
			} else { $idx++; }
		}
		$remove = [];$foundNon=false;$first=null;
		for ($i = count($phs)-1; $i >= 0; $i--) {
			$p = $phs[$i]; if (!$p['present']) continue;
			$isDef = $p['default'] !== null && $p['used'] !== null && $p['used'] === $p['default'];
			$isNon = $p['present'] && $p['default'] !== null && $p['used'] !== null && $p['used'] !== $p['default'];
			if ($isDef && !$foundNon) { if ($p['index'] !== null) { $remove[$p['index']]=true; $first ??= $p['index']; } continue; }
			if ($isNon) { $foundNon=true; if ($first!==null) { $hasLeft=false; for($j=0;$j<$i;$j++){ $L=$phs[$j]; if($L['present']) { $lDef=$L['default']!==null && $L['used']!==null && $L['used']===$L['default']; if($lDef && ($L['index']===null||!isset($remove[$L['index']]))) { $hasLeft=true; break; } } } if($hasLeft) unset($remove[$first]); } break; }
		}
		if ($remove) { $new=[]; foreach($segments as $k=>$s){ if(!isset($remove[$k])) $new[]=$s; } $segments=$new; $genPath='/' . implode('/', array_filter($segments, fn($s)=>$s!=='')); if($genPath==='') $genPath='/'; }
		return $genPath;
	}

	/**
	 * @param array<string,mixed> $server
	 * @param string[] $headerKeys
	 */
	private function resolveForwardedServerValue(array $server, array $headerKeys, string $forwardedParam): ?string
	{
		foreach ($headerKeys as $key) {
			if (!empty($server[$key])) {
				return (string) $server[$key];
			}
		}
		if (empty($server['HTTP_FORWARDED'])) {
			return null;
		}
		return $this->extractFromForwardedHeader((string) $server['HTTP_FORWARDED'], $forwardedParam);
	}

	private function extractFromForwardedHeader(string $header, string $field): ?string
	{
		$entries = explode(',', $header);
		foreach ($entries as $entry) {
			$pairs = preg_split('/\s*;\s*/', trim($entry)) ?: [];
			foreach ($pairs as $pair) {
				if ($pair === '') {
					continue;
				}
				$kv = explode('=', $pair, 2);
				if (count($kv) !== 2) {
					continue;
				}
				if (strcasecmp(trim($kv[0]), $field) === 0) {
					return trim($kv[1], "\" \t");
				}
			}
		}
		return null;
	}

	private function firstHeaderToken(?string $value): ?string
	{
		if ($value === null) {
			return null;
		}
		$parts = explode(',', $value);
		foreach ($parts as $part) {
			$trimmed = trim($part);
			if ($trimmed !== '') {
				return $trimmed;
			}
		}
		return null;
	}

	/**
	 * @return array{0: ?string, 1: ?int, 2: bool}
	 */
	private function parseHostAndPortHeader(?string $raw): array
	{
		$token = $this->firstHeaderToken($raw);
		if ($token === null || $token === '') {
			return [null, null, false];
		}
		$authority = '//' . ltrim($token, '/');
		$host = parse_url($authority, PHP_URL_HOST);
		$port = parse_url($authority, PHP_URL_PORT);
		if (!is_string($host) || $host === '') {
			return [null, null, false];
		}
		$explicit = $port !== null && $port !== false;
		return [$host, $explicit ? (int) $port : null, $explicit];
	}

	private function normaliseScheme(?string $scheme): ?string
	{
		if ($scheme === null || $scheme === '') {
			return null;
		}
		return strtolower($scheme);
	}

	/**
	 * @param array<string,mixed> $server
	 */
	private function detectSchemeFromServer(array $server): string
	{
		if (!empty($server['REQUEST_SCHEME'])) {
			return strtolower((string) $server['REQUEST_SCHEME']);
		}
		$https = $server['HTTPS'] ?? null;
		if (is_string($https)) {
			$flag = strtolower($https);
			if ($flag === 'on' || $flag === '1' || $flag === 'https') {
				return 'https';
			}
			if ($flag === 'off' || $flag === '0') {
				return 'http';
			}
		} elseif ($https === true) {
			return 'https';
		}
		if (!empty($server['SERVER_PORT']) && (int) $server['SERVER_PORT'] === 443) {
			return 'https';
		}
		return 'http';
	}

	private function buildAuthority(string $host, ?int $port, string $scheme, bool $forcePort): string
	{
		$authorityHost = $this->formatAuthorityHost($host);
		if ($port === null) {
			return $authorityHost;
		}
		if ($forcePort || AgaviToolkit::isPortNecessary($scheme, $port)) {
			return $authorityHost . ':' . $port;
		}
		return $authorityHost;
	}

	private function formatAuthorityHost(string $host): string
	{
		if (str_contains($host, ':') && !str_starts_with($host, '[')) {
			return '[' . $host . ']';
		}
		return $host;
	}

	/* ================= Legacy lifecycle API (compatibility layer) ================= */

	/**
	 * Legacy initialize() hook – stores AgaviContext & parameters and marks initialized.
	 * Kept lightweight; route definitions already built in constructor. Idempotent.
	 */
	public function initialize(AgaviContext $context, array $parameters = [])
	{
		$this->context = $context; // expose to subclasses
		$this->parameters = $parameters;
		$this->initialized = true;
		// Derive input for null-route generation when possible
		try {
			if ($this->input === '' && method_exists($context, 'getRequest') && ($rq = $context->getRequest())) {
				// Web requests usually provide PATH_INFO via server vars; defer to requestContext otherwise
				$pi = $this->requestContext->getPathInfo();
				if ($pi !== '') { $this->input = $pi; }
			}
		} catch (\Throwable) { /* ignore */ }
	}

	/** Legacy startup() hook. Marks started, no heavy logic needed. */
	public function startup()
	{
		$this->started = true;
	}

	/** Indicates whether routing should be considered enabled (subclasses override). */
	public function isEnabled(): bool
	{
		return true;
	}

	/** Reset state for worker reuse (FrankenPHP etc). */
	public function reset(): void
	{
		$this->input = '';
		$this->inputParameters = [];
		$this->legacyGenerated = [];
		$this->initialized = false;
		$this->started = false;
		// Do NOT clear $this->routes / $this->meta / $this->requestContext to keep configuration intact.
	}
}
