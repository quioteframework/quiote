<?php

namespace Quiote\Runtime;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quiote\Config\Config;
use Quiote\Context;
use Quiote\Event\Events;
use Quiote\Event\Lifecycle\ResponseSendingEvent;
use Quiote\Logging\Log;
use Quiote\Logging\LogContext;
use Quiote\Middleware\MiddlewarePipeline;
use Quiote\Support\CorrelationId;

/**
 * Turns a PSR-7 request into a response for one context.
 *
 * This is the per-request work that used to sit on {@see Context}: owning the middleware pipeline,
 * resolving the request's correlation id, opening the ambient logging scope, arming the
 * request-state flush, and emitting the last event that sees request and response together. None of
 * it is about being a context -- it is about serving a request against one -- and a context that
 * also handles requests is a context that cannot be asked "which profile am I" without also
 * carrying a middleware pipeline.
 *
 * Implements {@see RequestHandlerInterface} rather than merely resembling it, which
 * {@see Context::handle()} always did without declaring it.
 *
 * The pipeline is per handler, and therefore per context: a named context profile has its own
 * middleware stack. It survives across requests within that context's lifetime, which is safe
 * because the pipeline itself holds no request state.
 *
 * @since      4.0.0
 */
final class ContextRequestHandler implements RequestHandlerInterface
{
    private ?MiddlewarePipeline $pipeline = null;

    /**
     * @var        ?string This request's correlation id. Resolved per request; null before the
     *             first one. Deliberately not cleared between requests -- the next request
     *             overwrites it, and clearing would make a post-response read answer null where it
     *             used to answer the id of the request being finished.
     */
    private ?string $correlationId = null;

    public function __construct(private readonly Context $context) {}

    /**
     * This request's correlation id, or null outside a handled request.
     *
     * @since      4.0.0
     */
    public function correlationId(): ?string
    {
        return $this->correlationId;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $correlationId = $this->openRequestScope($request);

        // Ensure a WebRequest exists before the pipeline runs, so a later getRequest() during
        // rendering does not have to take the lazy rebuild path.
        $this->warmRequest();

        // Propagate the correlation id so middleware can use it without generating another
        // (which would cost a second random_bytes() and disagree with this one).
        $request = $request->withAttribute('quiote.rid', $correlationId);

        $response = $this->pipeline()->handle($request);
        $response = $this->exposeCorrelationId($response, $correlationId);

        // The last hook that sees the full request and response together. No-op with no listeners.
        Events::emitLazy(
            ResponseSendingEvent::class,
            static fn(): ResponseSendingEvent => new ResponseSendingEvent($request, $response),
        );

        return $response;
    }

    /**
     * Adopt or generate this request's correlation id, open a fresh logging scope for it, and arm
     * the request-state flush.
     *
     * @return     string The correlation id for this request.
     * @since      4.0.0
     */
    private function openRequestScope(ServerRequestInterface $request): string
    {
        // Adopt an inbound correlation id from the configured header (an upstream gateway, a
        // distributed trace) when present and sane; otherwise generate one. The header name is
        // configurable so it can match e.g. Azure Application Gateway's own.
        $correlationId = CorrelationId::fromRequest($request, $this->headerName())
            ?? CorrelationId::generate();
        $this->correlationId = $correlationId;

        // Start a fresh ambient logging scope so every line is correlatable by rid. The clear() is
        // defensive: it guards against a scope left by a prior worker request whose reset() did not
        // run. The authoritative between-request clear lives in the request-boundary cleanup.
        LogContext::clear();
        LogContext::enrich(['rid' => $correlationId]);

        // The authoritative anchor for the per-request flush claim. The request boundary re-arms it
        // too, but this also covers a runtime that serves requests without a reset between them.
        $this->context->beginRequest();

        return $correlationId;
    }

    /**
     * Build this request's {@see \Quiote\Request\WebRequest} now rather than on first use.
     *
     * A failure here is recoverable, because getRequest() retries the same construction lazily. It
     * is logged rather than swallowed because if the retry fails too, getRequest() reports that no
     * factory declaration was available -- which names the wrong cause. This is the only place the
     * real one is visible.
     *
     * @since      4.0.0
     */
    private function warmRequest(): void
    {
        try {
            $this->context->getContainer()->get(\Quiote\Request\WebRequest::class);
        } catch (\Throwable $e) {
            Log::for($this)->error(
                '[ContextRequestHandler] eager request construction failed, deferring to '
                . 'getRequest(): ' . $e::class . ': ' . $e->getMessage()
                . ' @ ' . $e->getFile() . ':' . $e->getLine(),
            );
        }
    }

    /**
     * Echo the correlation id back, so a caller or gateway can tie its request to our logs and
     * traces. Skipped when `core.correlation_id.expose` is off, and never overwrites a header an
     * action set explicitly.
     *
     * @since      4.0.0
     */
    private function exposeCorrelationId(ResponseInterface $response, string $correlationId): ResponseInterface
    {
        if (!Config::getBool('core.correlation_id.expose', true)) {
            return $response;
        }

        $header = $this->headerName();

        return $response->hasHeader($header)
            ? $response
            : $response->withHeader($header, $correlationId);
    }

    /**
     * The configured inbound/outbound correlation-id header name.
     *
     * @since      4.0.0
     */
    private function headerName(): string
    {
        $name = Config::getString('core.correlation_id.header', CorrelationId::DEFAULT_HEADER);

        return $name !== '' ? $name : CorrelationId::DEFAULT_HEADER;
    }

    /**
     * The middleware pipeline for this context, built on first use.
     *
     * @since      4.0.0
     */
    public function pipeline(): MiddlewarePipeline
    {
        return $this->pipeline ??= new MiddlewarePipeline($this->context);
    }

    /**
     * Whether the pipeline has been built yet. Answers without building one, unlike
     * {@see pipeline()}.
     *
     * @since      4.0.0
     */
    public function hasPipeline(): bool
    {
        return $this->pipeline !== null;
    }

    /**
     * Discard the built pipeline so the next request builds a fresh one.
     *
     * The pipeline is composed from {@see \Quiote\Middleware\MiddlewareCatalog} the first time it
     * is needed and then reused for the context's lifetime, which is the right trade for a worker
     * but means a later change to the catalog would otherwise never be seen. Anything that
     * reconfigures the catalog after a request has been served -- a test replacing the core stack,
     * a host reconfiguring middleware between runs -- has to call this.
     *
     * @since      4.0.0
     */
    public function forgetPipeline(): void
    {
        $this->pipeline = null;
    }
}
