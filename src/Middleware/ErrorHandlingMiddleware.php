<?php

namespace Agavi\Middleware;

use Agavi\Agavi;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Nyholm\Psr7\Response;
use Agavi\Execution\ExecutionState;
use Throwable;
use Agavi\Config\AgaviConfig;
use Agavi\Logging\AgaviDebugLogger;

/**
 * Catches unhandled throwables from downstream middleware/action dispatch and
 * produces a generic 500 (or mapped) response. Minimal for Phase 2; can be
 * extended to perform content negotiation (HTML/JSON) and structured logging.
 */
class ErrorHandlingMiddleware implements MiddlewareInterface
{
    /** @var callable|null */
    private $logger;

    /** @param callable(Throwable $e, ServerRequestInterface $r):void|null $logger */
    public function __construct(?callable $logger = null)
    {
        $this->logger = $logger;
        AgaviDebugLogger::debug('[ErrorHandlingMiddleware] initialized');
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            AgaviDebugLogger::debug('[ErrorHandlingMiddleware] processing request ' . (string)$request->getUri());
            return $handler->handle($request);            
        } catch (Throwable $e) {
            AgaviDebugLogger::error('[ErrorHandlingMiddleware] Caught exception ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());           
            return $this->renderExceptionResponse($request, $e);
        }
    }

    /**
     * Public helper so AgaviKernel (or other bootstrap code) can render a unified exception response.
     */
    public function renderExceptionResponse(ServerRequestInterface $request, Throwable $e): ResponseInterface
    {
        $debugTemplate = getenv('AGAVI_DEBUG_EXCEPTION_TEMPLATE') || getenv('AGAVI_DEBUG');
        $decisionLog = function(string $stage, array $data = []) use ($debugTemplate) {
            if(!$debugTemplate) { return; }
            try {
                $payload = ['diag_type' => 'exception_template_decision', 'stage' => $stage] + $data;
                $json = json_encode($payload, JSON_UNESCAPED_SLASHES|JSON_PARTIAL_OUTPUT_ON_ERROR);
                if($json) { @error_log('JAKAMO_DEV_DIAG ' . $json); }
            } catch (\Throwable) { /* ignore */ }
        };
        if ($this->logger) {
            try { ($this->logger)($e, $request); } catch (Throwable) { /* ignore */ }
        } else {
            // Fallback intrinsic logging: emit rich diagnostic if no external logger provided.
            try {
                $route = $request->getAttribute('_route');
                $mod = $request->getAttribute('_module');
                $act = $request->getAttribute('_action');
                $uri = (string)$request->getUri();
                $lastSql = null; $qCount = null; $lastSqls = [];
                if (class_exists(\Propel\Propel::class)) {
                    try {
                        $pdo = \Propel\Propel::getConnection('mdi');
                        if ($pdo) {
                            try {
                                $ref = new \ReflectionObject($pdo);
                                if ($ref->hasMethod('getLastExecutedQuery')) { $lastSql = $ref->getMethod('getLastExecutedQuery')->invoke($pdo); }
                                if ($ref->hasMethod('getQueryCount')) { $qCount = $ref->getMethod('getQueryCount')->invoke($pdo); }
                                // Attempt to retrieve rolling recent SQL array if present (custom extension)
                                if ($ref->hasProperty('recentSql')) {
                                    $prop = $ref->getProperty('recentSql');
                                    $prop->setAccessible(true);
                                    $val = $prop->getValue($pdo);
                                    if (is_array($val)) { $lastSqls = $val; }
                                }
                            } catch (\Throwable) { /* ignore */ }
                        }
                    } catch (\Throwable) { /* ignore */ }
                }
                $mem = round(memory_get_usage(true)/1048576,2);
                $peak = round(memory_get_peak_usage(true)/1048576,2);
                $traceFull = $e->getTraceAsString();
                $traceTrim = substr(str_replace("\n", ' | ', $traceFull),0,2000);
                // Build exception chain
                $chain = [];
                for ($cur = $e; $cur; $cur = $cur->getPrevious()) {
                    $chain[] = [
                        'class' => get_class($cur),
                        'message' => $cur->getMessage(),
                        'file' => $cur->getFile(),
                        'line' => $cur->getLine(),
                    ];
                }
                $payload = [
                    'diag_type' => 'middleware_exception',
                    'class' => get_class($e),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'uri' => $uri,
                    'module' => (string)$mod,
                    'action' => (string)$act,
                    'route' => (string)$route,
                    'last_sql' => (string)($lastSql ?? ''),
                    'query_count' => $qCount,
                    'last_sqls' => $lastSqls ? array_slice($lastSqls, -10) : null,
                    'mem_mb' => $mem,
                    'peak_mb' => $peak,
                    'trace' => $traceTrim,
                    'chain_depth' => count($chain),
                    'chain' => $chain,
                ];
                // JSON encode safely
                $json = null;
                try { $json = json_encode($payload, JSON_UNESCAPED_SLASHES|JSON_PARTIAL_OUTPUT_ON_ERROR); } catch (\Throwable) { $json = null; }
                if ($json === null) {
                    // Fallback minimal
                    $json = '"JAKAMO_EXCEPTION_LOG_FAIL"';
                }
                @error_log('JAKAMO_DEV_DIAG '.$json);
            } catch (Throwable) { /* ignore logging failure */ }
        }
        // Build exception chain
        $exceptions = [];
        for ($cur = $e; $cur; $cur = $cur->getPrevious()) { $exceptions[] = $cur; }
        $request = $request->withAttribute('exceptions', $exceptions)->withAttribute('exception', $e);

        $status = 500;
        $map = [\InvalidArgumentException::class => 400, \DomainException::class => 422];
        foreach ($map as $cls => $code) { if ($e instanceof $cls) { $status = $code; break; } }

        // JSON negotiation (basic): Accept header or explicit output_type=json
        $accept = strtolower($request->getHeaderLine('Accept'));
        $outputType = strtolower((string)($request->getAttribute('output_type') ?? ''));
        $wantsJson = str_contains($accept, 'application/json') || $outputType === 'json';
        $decisionLog('negotiate_start', [
            'accept' => $accept,
            'output_type_attr' => $outputType,
            'wants_json' => $wantsJson,
        ]);

        $env = AgaviConfig::get('core.environment');
        $isProd = $env && preg_match('/^(prod|production)/i', (string)$env);
        $mode = $isProd ? 'production' : 'development';
        // Correlation id: adopt standard 'Correlation-Id' primary, fallback legacy 'X-Correlation-ID'
        $cid = $request->getHeaderLine('Correlation-Id');
        if (!$cid) { $cid = $request->getHeaderLine('X-Correlation-ID'); }
        if (!$cid && function_exists('apache_request_headers')) {
            $h = apache_request_headers();
            if (!$cid && $h) {
                if (isset($h['Correlation-Id'])) { $cid = $h['Correlation-Id']; }
                elseif (isset($h['X-Correlation-ID'])) { $cid = $h['X-Correlation-ID']; }
            }
        }
        $request = $request->withAttribute('correlationId', $cid ?: null);

        if ($wantsJson) {
            // Resolve json template file if configured
            $jsonTemplate = $this->resolveStructuredTemplate('json', $mode);
            if ($jsonTemplate) {
                $decisionLog('json_template_selected', [ 'template' => $jsonTemplate, 'mode' => $mode ]);
                return $this->includeTemplateToResponse($jsonTemplate, $status, $request, $e, 'application/json');
            }
            $decisionLog('json_template_missing_fallback');
            // fallback legacy minimal payload
            $payload = ['error' => $status >= 500 ? 'Internal Server Error' : 'Request Error', 'type' => $e::class];
            if (getenv('AGAVI_DEBUG')) { $payload['message'] = $e->getMessage(); }
            if ($cid) { $payload['correlation_id'] = $cid; }
            $bodyJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
            if ($status >= 500) {
                @error_log('JAKAMO_DEV_DIAG {"diag_type":"middleware_response","format":"json","status":'.$status.',"body":'.($bodyJson !== false ? $bodyJson : 'null').'}');
            }
            return new Response($status, ['Content-Type' => 'application/json; charset=utf-8', 'X-Agavi-Error-Type' => $e::class], $bodyJson);
        }

        // HTML template resolution extended with new keys
        $htmlStructured = $this->resolveStructuredTemplate('html', $mode);
        if($htmlStructured) {
            $decisionLog('html_structured_selected', ['template' => $htmlStructured, 'mode' => $mode]);
        } else {
            $decisionLog('html_structured_missing_attempt_legacy');
        }
        $tplFile = $htmlStructured ?? self::resolveTemplateFileStatic($request);
        // Guard: if misconfiguration points HTML path at a JSON template, force fallback to real HTML
        if ($tplFile && preg_match('/json_(development|production)\.php$/', $tplFile)) {
            $decisionLog('html_guard_json_misconfig', ['selected' => $tplFile]);
            // Force fallback list ignoring configured default
            $tplFile = self::resolveTemplateFileStatic($request->withAttribute('__force_html_fallback', true));
        }
        if($tplFile && $htmlStructured !== $tplFile) {
            $decisionLog('html_legacy_selected', ['template' => $tplFile]);
        }
        $context = $this->extractContext($request);
    $baseLevel = ob_get_level();
    ob_start();
    $startedLevel = ob_get_level();
        try {
            $container = null; // legacy variable
            /** @noinspection PhpUnusedLocalVariableInspection */ $exceptionsChain = $exceptions; $rootException = $e;
            $correlationId = $cid ?? null;
            $decisionLog('html_including', ['template' => $tplFile]);
            include $tplFile;
            $body = ob_get_clean();
        } catch (Throwable $renderErr) {
            // Only unwind buffers we started (>= startedLevel) without touching buffers below baseLevel
            while (ob_get_level() >= $startedLevel && ob_get_level() > $baseLevel) { @ob_end_clean(); }
            $msg = getenv('AGAVI_DEBUG') ? 'Template render failed: '.$renderErr->getMessage() : 'Internal Server Error';
            $decisionLog('html_render_error', ['message' => $renderErr->getMessage(), 'template' => $tplFile]);
            return new Response($status, ['Content-Type' => 'text/plain; charset=utf-8', 'X-Agavi-Error-Type' => $e::class], $msg);
        }
        if ($body === '' || $body === false) { $body = getenv('AGAVI_DEBUG') ? 'Empty error template output' : 'Internal Server Error'; }
        $headers = [];
        if (!headers_sent()) { $headers['Content-Type'] = (str_contains($tplFile, 'plaintext') ? 'text/plain' : 'text/html').'; charset=utf-8'; }
        $headers['X-Agavi-Error-Type'] = $e::class;
        if ($status >= 500) {
            $snippet = substr($body, 0, 400);
            $snippetEsc = json_encode($snippet, JSON_UNESCAPED_SLASHES|JSON_PARTIAL_OUTPUT_ON_ERROR);
            @error_log('JAKAMO_DEV_DIAG {"diag_type":"middleware_response","format":"html","status":'.$status.',"body_snippet":'.$snippetEsc.',"length":'.strlen($body).'}');
        }
        $decisionLog('html_response_complete', ['status' => $status, 'length' => strlen($body)]);
    return new Response($status, $headers, $body);
    }

    private function extractContext(ServerRequestInterface $request): ?\Agavi\AgaviContext
    {
        // Attempt to recover context via global static or attribute on request
        // For now, rely on AgaviContext::getInstance() default profile.
        try {
            return \Agavi\AgaviContext::getInstance();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function resolveTemplateFileStatic(ServerRequestInterface $request): string
    {
        $accept = $request->getHeaderLine('Accept');
        $outputType = $request->getAttribute('output_type') ?? 'html';
        $forcePlain = str_contains($accept, 'text/plain') || $outputType === 'txt';
        $agaviDir = AgaviConfig::get('core.agavi_dir') ?: (__DIR__ . '/../..');
        $baseDir = rtrim($agaviDir, '/') . '/Exception/templates';
        $candidates = [];
        if ($forcePlain) {
            $candidates[] = $baseDir . '/plaintext.php';
        } else {
            // context-specific template first
            $ctxName = null;
            try {
                $ctxName = \Agavi\AgaviContext::getInstance()?->getName();
            } catch (\Throwable) {
            }
            if ($ctxName) {
                $key = 'exception.templates.' . $ctxName;
                if (AgaviConfig::has($key)) {
                    $candidates[] = AgaviConfig::get($key);
                }
            }
            // configured default
            $forceHtmlFallback = (bool)$request->getAttribute('__force_html_fallback');
            if (!$forceHtmlFallback && AgaviConfig::has('exception.default_template')) {
                $def = AgaviConfig::get('exception.default_template');
                if (is_string($def) && str_ends_with(strtolower($def), '.php')) {
                    $candidates[] = $def;
                } elseif (is_string($def)) {
                    $name = preg_replace('/\.php$/i', '', $def);
                    $candidates[] = $baseDir . '/' . $name . '.php';
                }
            }
            // fallbacks
            foreach (['shiny', 'simple', 'plaintext'] as $name) {
                $candidates[] = $baseDir . '/' . $name . '.php';
            }
        }
        foreach ($candidates as $p) {
            if (is_string($p) && is_file($p) && is_readable($p)) {
                return $p;
            }
        }
    return $baseDir . '/plaintext.php';
    }

    private function resolveStructuredTemplate(string $format, string $mode): ?string
    {
        // format: html|json, mode: production|development
        $agaviDir = AgaviConfig::get('core.agavi_dir') ?: (__DIR__ . '/../..');
        $baseDir = rtrim($agaviDir, '/') . '/Exception/templates';
        $candidates = [];
        if ($format === 'html') {
            // new application-level templates stored under core.template_dir/exception
            if (AgaviConfig::has("exception.templates.html.$mode")) {
                $candidates[] = AgaviConfig::get("exception.templates.html.$mode");
            }
        } elseif ($format === 'json') {
            if (AgaviConfig::has("exception.templates.json.$mode")) {
                $candidates[] = AgaviConfig::get("exception.templates.json.$mode");
            }
        }
        foreach ($candidates as $p) {
            if (is_string($p) && is_file($p) && is_readable($p)) { return $p; }
        }
        return null;
    }

    private function includeTemplateToResponse(string $file, int $status, ServerRequestInterface $request, Throwable $e, string $contentType): ResponseInterface
    {
        $exceptions = [];
        for ($cur = $e; $cur; $cur = $cur->getPrevious()) { $exceptions[] = $cur; }
    $baseLevel = ob_get_level();
    ob_start();
    $startedLevel = ob_get_level();
        try {
            $exceptionsChain = $exceptions; $rootException = $e; $correlationId = $request->getAttribute('correlationId');
            include $file;
            $body = ob_get_clean();
        } catch (Throwable $renderErr) {
            while (ob_get_level() >= $startedLevel && ob_get_level() > $baseLevel) { @ob_end_clean(); }
            $msg = getenv('AGAVI_DEBUG') ? 'Template render failed: '.$renderErr->getMessage() : 'Internal Server Error';
            return new Response($status, ['Content-Type' => 'text/plain; charset=utf-8', 'X-Agavi-Error-Type' => $e::class], $msg);
        }
        return new Response($status, ['Content-Type' => $contentType.'; charset=utf-8', 'X-Agavi-Error-Type' => $e::class], $body ?: '');
    }
}
