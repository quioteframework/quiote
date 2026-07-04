<?php

namespace Quiote\Middleware;

use Quiote\Quiote;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Exception\Rendering\ExceptionRenderer;
use Quiote\Exception\Rendering\ExceptionRendererRegistry;
use Quiote\Exception\Rendering\SafeRenderer;
use Throwable;
use Quiote\Config\Config;
use Quiote\Event\Events;
use Quiote\Event\Lifecycle\ExceptionCaughtEvent;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;

/**
 * Catches unhandled throwables from downstream middleware/action dispatch and
 * produces a generic 500 (or mapped) response. Currently minimal; can be
 * extended to perform content negotiation (HTML/JSON) and structured logging.
 */
#[\Quiote\Middleware\Attribute\Middleware(phase: 'bootstrap', priority: 1000)]
class ErrorHandlingMiddleware implements MiddlewareInterface
{
    /** @var callable|null */
    private $logger;

    /** @param callable(Throwable $e, ServerRequestInterface $r):void|null $logger */
    public function __construct(?callable $logger = null)
    {
        $this->logger = $logger;
        \Quiote\Logging\Log::for($this)->debug('[ErrorHandlingMiddleware] initialized');
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            \Quiote\Logging\Log::for($this)->debug('[ErrorHandlingMiddleware] processing request ' . (string)$request->getUri());
            return $handler->handle($request);
        } catch (Throwable $e) {
            \Quiote\Logging\Log::for($this)->error($this->buildDiagnosticLogLine($e, $request));
            return $this->renderExceptionResponse($request, $e);
        }
    }

    /**
     * Builds a single, information-dense log line for an uncaught exception: class, message,
     * throw site, the request that triggered it, exception-specific context (e.g. allowed HTTP
     * methods for routing failures), the full exception chain, and a full stack trace.
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
     * Public helper so Kernel (or other bootstrap code) can render a unified exception response.
     */
    public function renderExceptionResponse(ServerRequestInterface $request, Throwable $e): ResponseInterface
    {
        Events::emit(new ExceptionCaughtEvent($e, $request));

        if ($this->logger && \Quiote\Logging\Log::for($this)->isEnabled(\Quiote\Logging\Level::Debug)) {
            try {
                ($this->logger)($e, $request);
            } catch (Throwable) { /* ignore */
            }
        }

        $status = 500;
        $map = [\InvalidArgumentException::class => 400, \DomainException::class => 422];
        foreach ($map as $cls => $code) {
            if ($e instanceof $cls) {
                $status = $code;
                break;
            }
        }

        // Correlation id: adopt standard 'Correlation-Id' primary, fallback legacy 'X-Correlation-ID'
        $cid = $request->getHeaderLine('Correlation-Id');
        if (!$cid) {
            $cid = $request->getHeaderLine('X-Correlation-ID');
        }
        if (!$cid && function_exists('apache_request_headers')) {
            $h = apache_request_headers();
            if ($h) {
                if (isset($h['Correlation-Id'])) {
                    $cid = $h['Correlation-Id'];
                } elseif (isset($h['X-Correlation-ID'])) {
                    $cid = $h['X-Correlation-ID'];
                }
            }
        }
        $cid = $cid ?: null;

        $renderer = $this->resolveRenderer();
        \Quiote\Logging\Log::for($this)->debug(sprintf('[ErrorHandlingMiddleware] rendering via %s, status=%d', $renderer::class, $status));

        return $renderer->render($e, $request, $status, $cid);
    }

    /**
     * The sole signal is core.developer_exceptions -- no environment-name
     * sniffing, no QUIOTE_DEBUG. Default false: every client gets the safe
     * generic response unless a developer has explicitly opted in. The
     * developer renderer itself is resolved through {@see ExceptionRendererRegistry}
     * rather than a hardcoded class -- this middleware never names a concrete
     * developer renderer.
     */
    private function resolveRenderer(): ExceptionRenderer
    {
        if (!Config::get('core.developer_exceptions', false)) {
            return new SafeRenderer();
        }
        return ExceptionRendererRegistry::developerRenderer() ?? new SafeRenderer();
    }
}
