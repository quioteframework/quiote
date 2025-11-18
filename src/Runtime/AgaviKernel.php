<?php

namespace Agavi\Runtime;

use Agavi\Agavi;
use Agavi\Middleware\ErrorHandlingMiddleware;
use Agavi\Request\AgaviWebRequest;
use Agavi\Logging\AgaviDebugLogger;
use Agavi\Config\AgaviConfig;
use Agavi\Util\AgaviWorkerManager;
use Agavi\Runtime\Worker\FrankenPhpWorkerAdapter;
use Agavi\Runtime\Worker\SingleRequestAdapter;
use Agavi\Runtime\Worker\WorkerAdapterInterface;

class AgaviKernel
{
    private ?string $appDir = null;
    private bool $prewarm = false;
    private array $extraContexts = [];

    private function __construct(
        private string $env,
        private string $contextName,
    ) {}

    /**
     * Create kernel with optional overrides.
     * Options:
     *  - env: string environment
     *  - context: string primary context name
     *  - psr: bool enable PSR pipeline
     *  - app_dir: string application root (contains Config/, Modules/, etc.)
     *  - autoload_paths: string|array additional composer autoload files to require (app first)
     *  - prewarm: bool force prewarm
     *  - contexts: array additional contexts to pre-create
     */
    public static function create(array $options = []): self
    {

        $env = $options['env'] ?? getenv('AGAVI_ENV') ?: 'prod';
        $context = $options['context'] ?? getenv('AGAVI_CONTEXT') ?: 'web';
        $kernel = new self($env, $context);
        if (isset($options['app_dir'])) {
            $kernel->appDir = $options['app_dir'];
        }
        if (isset($options['prewarm'])) {
            $kernel->prewarm = (bool)$options['prewarm'];
        }
        if (isset($options['contexts']) && is_array($options['contexts'])) {
            $kernel->extraContexts = $options['contexts'];
        }
        return $kernel;
    }

    public function run(): void
    {
        $this->bootstrap();
        $context = Agavi::context($this->contextName, true);
        $adapter = $this->selectWorkerAdapter();
        $emitter = new HttpEmitter();

        $handle = function () use ($context, $emitter) {
            try {
                $request = $this->buildRequestFromGlobals();
                $response = $context->handle($request);
                $emitter->emit($response);
            } catch (\Throwable $e) {
                // Log basic diagnostics
                AgaviDebugLogger::debug('[AgaviKernel] Uncaught during handle bootstrap: '.get_class($e).': '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine(), $context);
                // Attempt unified error rendering via ErrorHandlingMiddleware helper.
                try {
                    $err = new ErrorHandlingMiddleware(function(\Throwable $ex, \Psr\Http\Message\ServerRequestInterface $r) use ($context) {
                        AgaviDebugLogger::debug('[AgaviKernel][late] '.get_class($ex).': '.$ex->getMessage(), $context);
                    });
                    // If original PSR request not built (rare), synthesize minimal one.
                    if(!isset($request) || !$request) {
                        $psr17 = new \Nyholm\Psr7\Factory\Psr17Factory();
                        $request = $psr17->createServerRequest('GET', '/error');
                    }
                    $resp = $err->renderExceptionResponse($request, $e);
                    $emitter->emit($resp);
                } catch(\Throwable $renderFail) {
                    // Only emit a raw fallback header if no Content-Type header has been sent/queued.
                    // Duplicate Content-Type headers were observed; guard against re-emission.
                    if (!headers_sent()) {
                        // Attempt to detect if any output buffering already contains an HTTP header by scanning headers_list()
                        $existing = function_exists('headers_list') ? headers_list() : [];
                        $hasCt = false;
                        foreach ($existing as $h) { if (stripos($h, 'Content-Type:') === 0) { $hasCt = true; break; } }
                        if (!$hasCt) {
                            header('Content-Type: text/plain; charset=utf-8', true, 500);
                        }
                    }
                    echo 'Internal Server Error';
                }
            }
            return true; // continue loop
        };

        $reset = function () use ($context) {
            if (class_exists(AgaviWorkerManager::class)) {
                AgaviWorkerManager::resetForNextRequest($context->getName());
            }
        };

        $adapter->run($handle, $reset);
    }

    private function bootstrap(): void
    {
        AgaviConfig::set('core.app_dir', $this->appDir, true, true);

        if (!AgaviConfig::has('core.default_context')) {
            AgaviConfig::set('core.default_context', $this->contextName, true, true);
        }

        // If APCu exists but has not been explicitly disabled
        // enable APCu
        if (!defined('AGAVI_USE_APCU_CONFIG_CACHE')) {
            define('AGAVI_USE_APCU_CONFIG_CACHE', function_exists('apcu_fetch'));
        }

        // Bootstrap (prewarm only if requested or option set)
        $contextsToPreCreate = array_unique(array_filter(array_merge([$this->contextName], $this->extraContexts)));
        Agavi::bootstrap($this->env, $contextsToPreCreate, ['prewarm' => $this->prewarm]);
    }

    private function selectWorkerAdapter(): WorkerAdapterInterface
    {
        if (FrankenPhpWorkerAdapter::isSupported()) {
            AgaviWorkerManager::configure([
                'max_requests_before_cleanup' => (int)(getenv('AGAVI_MAX_REQUESTS') ?: 1000),
                'preserve_route_cache' => true,
                'preserve_route_trie' => true,
                'preserve_callback_pool' => true,
                'reset_stats' => true,
            ]);
            return new FrankenPhpWorkerAdapter($this->contextName);
        }
        return new SingleRequestAdapter();
    }

    private function buildRequestFromGlobals(): \Psr\Http\Message\ServerRequestInterface
    {
        // Build a spec-compliant ServerRequest via Nyholm factories, then wrap directly in AgaviWebRequest.
        static $creator = null;
        if ($creator === null) {
            $psr17 = new \Nyholm\Psr7\Factory\Psr17Factory();
            $creator = new \Nyholm\Psr7Server\ServerRequestCreator($psr17, $psr17, $psr17, $psr17);
        }
        $base = $creator->fromGlobals(); // Body parsing/JSON handled later by middleware.
        $base = $this->applyReverseProxyAdjustments($base);
        $agaviReq = new AgaviWebRequest();
        $agaviReq->attachPsrRequest($base);
        return $agaviReq;
    }

    private function applyReverseProxyAdjustments(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ServerRequestInterface
    {
        $server = $request->getServerParams();
        $forwardedHostRaw = $this->resolveForwardedValue($server, ['HTTP_X_ORIGINAL_HOST', 'HTTP_X_FORWARDED_HOST'], 'host');
        $forwardedProtoRaw = $this->resolveForwardedValue($server, ['HTTP_X_FORWARDED_PROTO'], 'proto');
        $forwardedPortRaw = $this->resolveForwardedValue($server, ['HTTP_X_FORWARDED_PORT'], 'port');

        if ($forwardedHostRaw === null && $forwardedProtoRaw === null && $forwardedPortRaw === null) {
            return $request;
        }

        [$hostOverride, $portFromHost, $hostPortExplicit] = $this->parseHostAndPort($forwardedHostRaw);
        $schemeOverride = $this->normaliseScheme($this->firstHeaderToken($forwardedProtoRaw));
        $portOverride = $portFromHost;
        $portExplicit = $hostPortExplicit;

        if ($portOverride === null && $forwardedPortRaw !== null) {
            $token = $this->firstHeaderToken($forwardedPortRaw);
            if ($token !== null && $token !== '' && is_numeric($token)) {
                $portOverride = (int) $token;
                $portExplicit = true;
            }
        }

        $uri = $request->getUri();
        $modified = false;

        if ($schemeOverride !== null && $schemeOverride !== '' && $schemeOverride !== $uri->getScheme()) {
            $uri = $uri->withScheme($schemeOverride);
            $modified = true;
        } else {
            $schemeOverride = $uri->getScheme();
        }

        if ($hostOverride !== null && $hostOverride !== '' && $hostOverride !== $uri->getHost()) {
            $uri = $uri->withHost($hostOverride);
            $modified = true;
        } else {
            $hostOverride = $uri->getHost();
        }

        if ($portOverride !== null && $portOverride !== $uri->getPort()) {
            $uri = $uri->withPort($portOverride);
            $modified = true;
        } else {
            $portOverride = $uri->getPort();
        }

        if ($modified) {
            $request = $request->withUri($uri, false);
            $this->updateServerGlobalsForProxy($uri, $portExplicit);
        }

        return $request;
    }

    /**
     * Prefer explicit reverse-proxy headers for scheme/host/port while still respecting RFC 7239 Forwarded.
     * @param array<string,mixed> $server
     * @param string[] $headerKeys
     */
    private function resolveForwardedValue(array $server, array $headerKeys, string $forwardedParam): ?string
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
    private function parseHostAndPort(?string $raw): array
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

    private function updateServerGlobalsForProxy(\Psr\Http\Message\UriInterface $uri, bool $forcePort): void
    {
        $host = $uri->getHost();
        if ($host === '') {
            return;
        }

        $port = $uri->getPort();
        $scheme = $uri->getScheme();
        $authorityHost = $this->formatAuthorityHost($host);
        if ($port !== null && ($forcePort || $this->isPortNonDefault($scheme, $port))) {
            $authorityHost .= ':' . $port;
        }

        $_SERVER['HTTP_HOST'] = $authorityHost;
        $_SERVER['SERVER_NAME'] = $host;
        if ($port !== null) {
            $_SERVER['SERVER_PORT'] = (string) $port;
        }
        if ($scheme !== '') {
            $_SERVER['REQUEST_SCHEME'] = $scheme;
        }
    }

    private function formatAuthorityHost(string $host): string
    {
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            return '[' . $host . ']';
        }
        return $host;
    }

    private function isPortNonDefault(?string $scheme, int $port): bool
    {
        $scheme = strtolower((string) $scheme);
        if ($scheme === 'http' && $port === 80) {
            return false;
        }
        if ($scheme === 'https' && $port === 443) {
            return false;
        }
        return true;
    }
}
