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
use Symfony\Component\Routing\Exception\MethodNotAllowedException;

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
            AgaviDebugLogger::error($this->buildDiagnosticLogLine($e, $request));
            return $this->renderExceptionResponse($request, $e);
        }
    }

    /**
     * Builds a single, information-dense log line for an uncaught exception: class, message,
     * throw site, the request that triggered it, exception-specific context (e.g. allowed HTTP
     * methods for routing failures), the full exception chain, and a full stack trace.
     *
     * The previous version of this log line only included class/message/file:line, which is
     * useless for exceptions like MethodNotAllowedException whose message is often empty —
     * there was no way to tell which request or call path caused it.
     */
    private function buildDiagnosticLogLine(Throwable $e, ServerRequestInterface $request): string
    {
        $lines = [];
        $lines[] = '[ErrorHandlingMiddleware] Caught exception ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
        $lines[] = 'request: ' . $request->getMethod() . ' ' . (string)$request->getUri();

        if ($e instanceof MethodNotAllowedException) {
            $lines[] = 'allowedMethods: ' . implode(', ', $e->getAllowedMethods());
        }

        $chain = [];
        for ($cur = $e->getPrevious(); $cur; $cur = $cur->getPrevious()) {
            $chain[] = $cur::class . ': ' . $cur->getMessage() . ' @ ' . $cur->getFile() . ':' . $cur->getLine();
        }
        if ($chain) {
            $lines[] = 'causedBy: ' . implode(' <- ', $chain);
        }

        $lines[] = 'trace: ' . $e->getTraceAsString();

        return implode(' | ', $lines);
    }

    /**
     * Public helper so AgaviKernel (or other bootstrap code) can render a unified exception response.
     */
    public function renderExceptionResponse(ServerRequestInterface $request, Throwable $e): ResponseInterface
    {
        if ($this->logger && \Agavi\Util\DebugFlags::$exceptionTemplate) {
            try {
                ($this->logger)($e, $request);
            } catch (Throwable) { /* ignore */
            }
        }

        // Build exception chain
        $exceptions = [];
        for ($cur = $e; $cur; $cur = $cur->getPrevious()) {
            $exceptions[] = $cur;
        }
        $request = $request->withAttribute('exceptions', $exceptions)->withAttribute('exception', $e);

        $status = 500;
        $map = [\InvalidArgumentException::class => 400, \DomainException::class => 422];
        foreach ($map as $cls => $code) {
            if ($e instanceof $cls) {
                $status = $code;
                break;
            }
        }

        $tplFile = self::resolveTemplateFileStatic($request);

        // JSON negotiation (basic): Accept header or explicit output_type=json
        $accept = strtolower($request->getHeaderLine('Accept'));
        $outputType = strtolower((string)($request->getAttribute('output_type') ?? ''));
        $wantsJson = str_contains($accept, 'application/json') || $outputType === 'json';
        AgaviDebugLogger::debug(sprintf('[ErrorHandlingMiddleware] content negotiation: accept=%s output_type=%s wants_json=%s', $accept, $outputType, $wantsJson ? '1' : '0'));

        $env = AgaviConfig::get('core.environment');
        $isProd = $env && preg_match('/^(prod|production)/i', (string)$env);
        $mode = $isProd ? 'production' : 'development';
        // Correlation id: adopt standard 'Correlation-Id' primary, fallback legacy 'X-Correlation-ID'
        $cid = $request->getHeaderLine('Correlation-Id');
        if (!$cid) {
            $cid = $request->getHeaderLine('X-Correlation-ID');
        }
        if (!$cid && function_exists('apache_request_headers')) {
            $h = apache_request_headers();
            if (!$cid && $h) {
                if (isset($h['Correlation-Id'])) {
                    $cid = $h['Correlation-Id'];
                } elseif (isset($h['X-Correlation-ID'])) {
                    $cid = $h['X-Correlation-ID'];
                }
            }
        }
        $request = $request->withAttribute('correlationId', $cid ?: null);

        if ($wantsJson) {
            // Resolve json template file if configured
            $jsonTemplate = $this->resolveStructuredTemplate('json', $mode);
            if ($jsonTemplate) {
                if (\Agavi\Util\DebugFlags::$exceptionTemplate) {
                    AgaviDebugLogger::debug(sprintf('[ErrorHandlingMiddleware] JSON template selected, template=%s mode=%s', $jsonTemplate, $mode));
                }
                return $this->includeTemplateToResponse($jsonTemplate, $status, $request, $e, 'application/json');
            }
            if (\Agavi\Util\DebugFlags::$exceptionTemplate) {
                AgaviDebugLogger::debug(sprintf('[ErrorHandlingMiddleware] JSON template missing, falling back to minimal payload'));
            }
            // fallback legacy minimal payload
            $payload = ['error' => $status >= 500 ? 'Internal Server Error' : 'Request Error', 'type' => $e::class];
            if (getenv('AGAVI_DEBUG')) {
                $payload['message'] = $e->getMessage();
            }
            if ($cid) {
                $payload['correlation_id'] = $cid;
            }
            $bodyJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
            return new Response($status, ['Content-Type' => 'application/json; charset=utf-8', 'X-Agavi-Error-Type' => $e::class], $bodyJson);
        }

        // Fast-path (moved after correlation id extraction): dev/plaintext minimal body including message (no template)
        if(!$isProd && getenv('AGAVI_DEBUG') && str_contains($accept, 'text/plain')) {
            $plain = $e->getMessage();
            if($plain === '') { $plain = $status >= 500 ? 'Internal Server Error' : 'Request Error'; }
            if($cid) { $plain .= "\nCorrelation-Id: " . $cid; }
            return new Response($status, ['Content-Type' => 'text/plain; charset=utf-8', 'X-Agavi-Error-Type' => $e::class], $plain);
        }

        // HTML template resolution extended with new keys
        $htmlStructured = $this->resolveStructuredTemplate('html', $mode);
        if ($htmlStructured && \Agavi\Util\DebugFlags::$exceptionTemplate) {
            AgaviDebugLogger::debug(sprintf('[ErrorHandlingMiddleware] HTML template selected, template=%s mode=%s', $htmlStructured, $mode));
        } else {
            AgaviDebugLogger::debug(sprintf('[ErrorHandlingMiddleware] HTML template missing, attempting legacy'));
        }
        // Guard: if misconfiguration points HTML path at a JSON template, force fallback to real HTML
        if ($tplFile && preg_match('/json_(development|production)\.php$/', $tplFile)) {
            AgaviDebugLogger::debug(sprintf('[ErrorHandlingMiddleware] HTML guard: JSON misconfiguration detected, forcing fallback to HTML'));
            // Force fallback list ignoring configured default
            $tplFile = self::resolveTemplateFileStatic($request->withAttribute('__force_html_fallback', true));
        }
        if ($tplFile && $htmlStructured !== $tplFile) {
            AgaviDebugLogger::debug(sprintf('[ErrorHandlingMiddleware] HTML legacy template selected, template=%s', $tplFile));
        }
        $context = $this->extractContext($request);
        $baseLevel = ob_get_level();
        ob_start();
        $startedLevel = ob_get_level();
        try {
            $container = null; // legacy variable
            /** @noinspection PhpUnusedLocalVariableInspection */ $exceptionsChain = $exceptions;
            $rootException = $e;
            $correlationId = $cid ?? null;
            AgaviDebugLogger::debug(sprintf('[ErrorHandlingMiddleware] including template %s', $tplFile));
            include $tplFile;
            $body = ob_get_clean();
        } catch (Throwable $renderErr) {
            // Only unwind buffers we started (>= startedLevel) without touching buffers below baseLevel
            while (ob_get_level() >= $startedLevel && ob_get_level() > $baseLevel) {
                @ob_end_clean();
            }
            $msg = \Agavi\Util\DebugFlags::$exceptionTemplate ? 'Template render failed: ' . $renderErr->getMessage() : 'Internal Server Error';
            AgaviDebugLogger::error(sprintf('[ErrorHandlingMiddleware] Template render failed: %s template=%s', $renderErr->getMessage(), $tplFile));
            return new Response($status, ['Content-Type' => 'text/plain; charset=utf-8', 'X-Agavi-Error-Type' => $e::class], $msg);
        }
        if ($body === '' || $body === false) {
            $body = \Agavi\Util\DebugFlags::$exceptionTemplate ? 'Empty error template output' : 'Internal Server Error';
        }
        // Development visibility: if in dev mode with AGAVI_DEBUG and 4xx/5xx, ensure exception message present for easier debugging
        $env = AgaviConfig::get('core.environment');
        $isProd = $env && preg_match('/^(prod|production)/i', (string)$env);
        if (!$isProd && getenv('AGAVI_DEBUG') && ($status >= 400) && !str_contains($body, $e->getMessage())) {
            // Append message safely (HTML escape unless plaintext template heuristics)
            $isPlain = str_contains($tplFile, 'plaintext') || str_contains($body, '<!-- PLAINTEXT -->');
            $msgOut = $e->getMessage();
            if (!$isPlain) {
                $msgOut = htmlspecialchars($msgOut, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
            $body .= (str_ends_with($body, "\n") ? '' : "\n") . ($isPlain ? "Exception: " : '<div class="agavi-exception-message">') . $msgOut . ($isPlain ? '' : '</div>');
        }
        $headers = [];
        if (!headers_sent()) {
            $headers['Content-Type'] = (str_contains($tplFile, 'plaintext') ? 'text/plain' : 'text/html') . '; charset=utf-8';
        }
        $headers['X-Agavi-Error-Type'] = $e::class;

        AgaviDebugLogger::debug(sprintf('[ErrorHandlingMiddleware] HTML response complete, status=%d length=%d', $status, strlen($body)));

        if ($status >= 500 && (\Agavi\Util\DebugFlags::$exceptionTemplate)) {
            $snippet = substr($body, 0, 400);
            $snippetEsc = json_encode($snippet, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
            AgaviDebugLogger::debug(sprintf('[ErrorHandlingMiddleware] HTML response snippet: body_snippet=%s', $snippetEsc));
        }

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
        $baseDir = rtrim((string) $agaviDir, '/') . '/Exception/templates';
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
        $baseDir = rtrim((string) $agaviDir, '/') . '/Exception/templates';
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
            if (is_string($p) && is_file($p) && is_readable($p)) {
                return $p;
            }
        }
        return null;
    }

    private function includeTemplateToResponse(string $file, int $status, ServerRequestInterface $request, Throwable $e, string $contentType): ResponseInterface
    {
        $exceptions = [];
        for ($cur = $e; $cur; $cur = $cur->getPrevious()) {
            $exceptions[] = $cur;
        }
        $baseLevel = ob_get_level();
        ob_start();
        $startedLevel = ob_get_level();
        try {
            $exceptionsChain = $exceptions;
            $rootException = $e;
            $correlationId = $request->getAttribute('correlationId');
            include $file;
            $body = ob_get_clean();
        } catch (Throwable $renderErr) {
            while (ob_get_level() >= $startedLevel && ob_get_level() > $baseLevel) {
                @ob_end_clean();
            }
            $msg = getenv('AGAVI_DEBUG') ? 'Template render failed: ' . $renderErr->getMessage() : 'Internal Server Error';
            return new Response($status, ['Content-Type' => 'text/plain; charset=utf-8', 'X-Agavi-Error-Type' => $e::class], $msg);
        }
        return new Response($status, ['Content-Type' => $contentType . '; charset=utf-8', 'X-Agavi-Error-Type' => $e::class], $body ?: '');
    }
}
