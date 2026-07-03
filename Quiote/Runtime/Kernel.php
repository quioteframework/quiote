<?php

namespace Quiote\Runtime;

use Quiote\Quiote;
use Quiote\Middleware\ErrorHandlingMiddleware;
use Quiote\Request\WebRequest;
use Quiote\Config\Config;
use Quiote\Util\WorkerManager;
use Quiote\Runtime\Worker\FrankenPhpWorkerAdapter;
use Quiote\Runtime\Worker\SingleRequestAdapter;
use Quiote\Runtime\Worker\WorkerAdapterInterface;

class Kernel
{
    private ?string $appDir = null;
    private bool $prewarm = false;
    private array $extraContexts = [];

    private function __construct(
        private readonly string $env,
        private readonly string $contextName,
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

        $env = $options['env'] ?? getenv('QUIOTE_ENV') ?: 'prod';
        $context = $options['context'] ?? getenv('QUIOTE_CONTEXT') ?: 'web';
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
        $context = Quiote::context($this->contextName, true);
        $adapter = $this->selectWorkerAdapter();
        $emitter = new HttpEmitter();

        $handle = function () use ($context, $emitter) {
            try {
                $request = $this->buildRequestFromGlobals();
                $response = $context->handle($request);
                $emitter->emit($response);
            } catch (\Throwable $e) {
                // Log basic diagnostics
                \Quiote\Logging\Log::for($this)->debug('[Kernel] Uncaught during handle bootstrap: '.$e::class.': '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
                // Backstop for a pre-pipeline failure (docs/OPENTELEMETRY_PLAN.md,
                // Phase 3) — TelemetryMiddleware never got a chance to run, so
                // whatever span (if any) is active gets the exception recorded here.
                \Quiote\Telemetry\Trace::current()->recordException($e)->setStatusError($e->getMessage());
                // Attempt unified error rendering via ErrorHandlingMiddleware helper.
                try {
                    $err = new ErrorHandlingMiddleware(function(\Throwable $ex, \Psr\Http\Message\ServerRequestInterface $r) use ($context): void {
                        \Quiote\Logging\Log::for($this)->debug('[Kernel][late] '.$ex::class.': '.$ex->getMessage());
                    });
                    // If original PSR request not built (rare), synthesize minimal one.
                    if(!isset($request) || !$request) {
                        $psr17 = new \Nyholm\Psr7\Factory\Psr17Factory();
                        $request = $psr17->createServerRequest('GET', '/error');
                    }
                    $resp = $err->renderExceptionResponse($request, $e);
                    $emitter->emit($resp);
                } catch(\Throwable) {
                    // Only emit a raw fallback header if no Content-Type header has been sent/queued.
                    // Duplicate Content-Type headers were observed; guard against re-emission.
                    if (!headers_sent()) {
                        // Attempt to detect if any output buffering already contains an HTTP header by scanning headers_list()
                        $existing = function_exists('headers_list') ? headers_list() : [];
                        $hasCt = array_any($existing, fn($h) => stripos($h, 'Content-Type:') === 0);
                        if (!$hasCt) {
                            header('Content-Type: text/plain; charset=utf-8', true, 500);
                        }
                    }
                    echo 'Internal Server Error';
                }
            }
            return true; // continue loop
        };

        $reset = function () use ($context): void {
            if (class_exists(WorkerManager::class)) {
                WorkerManager::resetForNextRequest($context->getName());
            }
            \Quiote\Telemetry\TelemetryBootstrap::flushAfterRequest();
        };

        $adapter->run($handle, $reset);
    }

    private function bootstrap(): void
    {
        Config::set('core.app_dir', $this->appDir, true, true);

        if (!Config::has('core.default_context')) {
            Config::set('core.default_context', $this->contextName, true, true);
        }

        // If APCu exists AND is actually enabled for this SAPI, use it for the
        // config cache. function_exists() alone is not enough: the extension can
        // be loaded but disabled (e.g. apc.enable_cli=0 on the CLI), in which case
        // apcu_store()/apcu_fetch() silently no-op and the APCu cache path would
        // store nothing yet still report itself active. apcu_enabled() reflects the
        // real runtime state, matching the check APCuConfigCache uses.
        if (!defined('QUIOTE_USE_APCU_CONFIG_CACHE')) {
            define('QUIOTE_USE_APCU_CONFIG_CACHE', function_exists('apcu_enabled') && apcu_enabled());
        }

        // Bootstrap (prewarm only if requested or option set)
        $contextsToPreCreate = array_unique(array_filter(array_merge([$this->contextName], $this->extraContexts)));
        Quiote::bootstrap($this->env, $contextsToPreCreate, ['prewarm' => $this->prewarm]);

        // Build the worker-lifetime telemetry providers now that settings.xml has
        // loaded (docs/OPENTELEMETRY_PLAN.md, Phase 2). Exactly once per worker
        // process; a no-op when telemetry.enabled is false or the SDK isn't
        // installed.
        \Quiote\Telemetry\TelemetryBootstrap::configureFromConfig();
    }

    private function selectWorkerAdapter(): WorkerAdapterInterface
    {
        if (FrankenPhpWorkerAdapter::isSupported()) {
            WorkerManager::configure([
                'max_requests_before_cleanup' => (int)(getenv('QUIOTE_MAX_REQUESTS') ?: 1000),
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
        // Apply reverse proxy adjustments to $_SERVER BEFORE creating PSR-7 request
        // so that the server params snapshot includes the corrected values
        $this->preAdjustServerGlobalsForProxy($_SERVER);
        
        // Build a spec-compliant ServerRequest via Nyholm factories, then wrap directly in WebRequest.
        static $creator = null;
        if ($creator === null) {
            $psr17 = new \Nyholm\Psr7\Factory\Psr17Factory();
            $creator = new \Nyholm\Psr7Server\ServerRequestCreator($psr17, $psr17, $psr17, $psr17);
        }
        $base = $creator->fromGlobals(); // Body parsing/JSON handled later by middleware.
        
        // Create WebRequest from PSR-7 request (WebRequest extends ServerRequest)
        $quioteReq = new WebRequest(
            $base->getMethod(),
            $base->getUri(),
            $base->getHeaders(),
            $base->getBody(),
            $base->getProtocolVersion(),
            $base->getServerParams()
        );
        $quioteReq = $quioteReq
            ->withQueryParams($base->getQueryParams())
            ->withCookieParams($base->getCookieParams())
            ->withParsedBody($base->getParsedBody())
            ->withUploadedFiles($base->getUploadedFiles());
        foreach ($base->getAttributes() as $name => $value) {
            $quioteReq = $quioteReq->withAttribute($name, $value);
        }
        return $quioteReq;
    }
    
    /**
     * Pre-adjust $_SERVER globals based on X-Forwarded-* headers before PSR-7 request creation
     */
    private function preAdjustServerGlobalsForProxy(array $server): void
    {
        $forwardedHostRaw = $this->resolveForwardedValue($server, ['HTTP_X_ORIGINAL_HOST', 'HTTP_X_FORWARDED_HOST'], 'host');
        $forwardedProtoRaw = $this->resolveForwardedValue($server, ['HTTP_X_FORWARDED_PROTO'], 'proto');
        $forwardedPortRaw = $this->resolveForwardedValue($server, ['HTTP_X_FORWARDED_PORT'], 'port');

        if ($forwardedHostRaw === null && $forwardedProtoRaw === null && $forwardedPortRaw === null) {
            return;
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

        // Update $_SERVER globals directly
        if ($schemeOverride !== null && $schemeOverride !== '') {
            $_SERVER['REQUEST_SCHEME'] = $schemeOverride;
            if ($schemeOverride === 'https') {
                $_SERVER['HTTPS'] = 'on';
            }
        }

        if ($hostOverride !== null && $hostOverride !== '') {
            $authorityHost = $this->formatAuthorityHost($hostOverride);
            // Only include port in HTTP_HOST if it's non-default
            if ($portOverride !== null && $this->isPortNonDefault($schemeOverride ?? 'http', $portOverride)) {
                $authorityHost .= ':' . $portOverride;
            }
            $_SERVER['HTTP_HOST'] = $authorityHost;
            $_SERVER['SERVER_NAME'] = $hostOverride;
        }

        if ($portOverride !== null) {
            $_SERVER['SERVER_PORT'] = (string) $portOverride;
        }
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
