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
use Quiote\Context;
use Quiote\Event\Events;
use Quiote\Request\RequestState;
use Quiote\Support\CorrelationId;
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

    private \Quiote\Logging\CategoryLogger $categoryLogger;

    /**
     * @param callable(Throwable $e, ServerRequestInterface $r):void|null $logger
     *
     * $context is optional (and untyped callers, e.g. every existing test, keep
     * constructing this with just $logger) so that a caught exception can be
     * published onto {@see RequestState} for {@see \Quiote\Replay\Recording\RecorderMiddleware} --
     * outermost in the stack -- to read back in `finishRecording()`. Without this, a
     * request recorder only ever observes an exception that escapes this middleware
     * entirely, which this middleware exists precisely to prevent.
     */
    public function __construct(?callable $logger = null, private readonly ?Context $context = null)
    {
        $this->logger = $logger;
        $this->categoryLogger = \Quiote\Logging\Log::for($this);
        if ($this->categoryLogger->isEnabled(\Quiote\Logging\Level::Debug)) {
            $this->categoryLogger->debug('[ErrorHandlingMiddleware] initialized');
        }
    }

    /**
     * Catches any throwable escaping the rest of the stack and renders it as a response.
     *
     * Logs a single diagnostic line carrying the exception class, message,
     * throw site, the triggering request, the cause chain and the trace, then
     * hands the exception to {@see renderExceptionResponse()} for the actual
     * body and status. Nothing propagates out of here, which is the point of
     * its high `bootstrap` priority: everything ordered inside it is covered.
     *
     * Middleware that must see error and 404 responses has to be ordered
     * *outside* this one; `after: ErrorHandlingMiddleware` places it within
     * the try and it will be skipped whenever an exception is thrown.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            // This middleware is outermost and always runs, even with debug off,
            // so the getUri()/string-cast/concat cost must stay behind the gate
            // rather than only the eventual sink write.
            if ($this->categoryLogger->isEnabled(\Quiote\Logging\Level::Debug)) {
                $this->categoryLogger->debug('[ErrorHandlingMiddleware] processing request ' . (string)$request->getUri());
            }
            return $handler->handle($request);
        } catch (Throwable $e) {
            $this->categoryLogger->error($this->buildDiagnosticLogLine($e, $request));
            $this->publishCaughtException($request, $e);
            return $this->renderExceptionResponse($request, $e);
        }
    }

    /**
     * Publishes the caught exception as a request attribute via {@see RequestState},
     * the same seam {@see \Quiote\Middleware\RoutingMiddleware} and
     * {@see \Quiote\Middleware\DispatchMiddleware} already use to surface state to
     * middleware sitting outside the PSR-7 clone chain. tryGet(), not get(): a test
     * double's fabricated Context/Container legitimately has no RequestState bound,
     * and that must stay a no-op rather than a crash.
     */
    private function publishCaughtException(ServerRequestInterface $request, Throwable $e): void
    {
        $requestState = $this->context?->getContainer()->tryGet(RequestState::class);
        if ($requestState instanceof RequestState) {
            $requestState->publish($request->withAttribute(Throwable::class, $e));
        }
    }

    /**
     * Builds a single, information-dense log line for an uncaught exception: class, message,
     * throw site, the request that triggered it, exception-specific context (e.g. allowed HTTP
     * methods for routing failures), the full exception chain, and a full stack trace.
     *
     * The request and call path matter because an exception like MethodNotAllowedException
     * often carries an empty message, leaving class/message/file:line alone with nothing to
     * identify what triggered it.
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
        Events::emitLazy(ExceptionCaughtEvent::class, static fn() => new ExceptionCaughtEvent($e, $request));

        if ($this->logger && $this->categoryLogger->isEnabled(\Quiote\Logging\Level::Debug)) {
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

        // Correlation id: adopt standard 'Correlation-Id' primary, fallback legacy
        // 'X-Correlation-ID'. Every candidate goes through CorrelationId::sanitize()
        // -- the value is client-supplied and ends up in a log line and a rendered
        // body, so control bytes (CR/LF, the log-injection vector) are stripped and
        // the length is capped. This path used to adopt the raw header.
        $cid = CorrelationId::sanitize($request->getHeaderLine('Correlation-Id'))
            ?? CorrelationId::sanitize($request->getHeaderLine('X-Correlation-ID'));
        if ($cid === null && function_exists('apache_request_headers')) {
            $h = apache_request_headers();
            if ($h) {
                $raw = $h['Correlation-Id'] ?? $h['X-Correlation-ID'] ?? null;
                if (is_string($raw)) {
                    $cid = CorrelationId::sanitize($raw);
                }
            }
        }

        $renderer = $this->resolveRenderer();
        if ($this->categoryLogger->isEnabled(\Quiote\Logging\Level::Debug)) {
            $this->categoryLogger->debug(sprintf('[ErrorHandlingMiddleware] rendering via %s, status=%d', $renderer::class, $status));
        }

        return $renderer->render($e, $request, $status, $cid);
    }

    /**
     * The sole signal is core.developer_exceptions -- no environment-name
     * sniffing, no QUIOTE_DEBUG. Default false: every client gets the safe
     * generic response unless a developer has explicitly opted in. Neither
     * renderer is hardcoded -- both are resolved through
     * {@see ExceptionRendererRegistry}, falling back to {@see SafeRenderer}
     * when nothing is registered for the relevant slot.
     */
    private function resolveRenderer(): ExceptionRenderer
    {
        if (!Config::getBool('core.developer_exceptions', false)) {
            return ExceptionRendererRegistry::safeRenderer() ?? new SafeRenderer();
        }
        return ExceptionRendererRegistry::developerRenderer() ?? new SafeRenderer();
    }
}
