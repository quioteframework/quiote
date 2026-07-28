<?php

declare(strict_types=1);

namespace Quiote\Runtime\Worker;

use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Quiote\Context;
use Quiote\Event\Events;
use Quiote\Event\Lifecycle\WorkerRequestCompletedEvent;
use Quiote\Http\Sse\SseStream;
use Quiote\Logging\Log;
use Quiote\Runtime\ErrorResponseFactory;
use Quiote\Runtime\OutputCapture;
use Quiote\Runtime\Request\WorkerRequestFactory;
use Quiote\Runtime\Session\NativeSessionCookieBridge;
use Quiote\Runtime\Superglobals\SuperglobalBridge;
use Quiote\Util\WorkerManager;
use Throwable;

/**
 * Everything a worker runtime needs from the framework, so a runtime only has
 * to know how to get a request in and a response out.
 *
 * The compensations for leaving the SAPI behind all live here rather than being
 * re-implemented per runtime, gated on {@see WorkerRuntimeCapabilities}:
 * superglobal hydration, stray-output capture, native session-cookie synthesis.
 * A runtime that reports SAPI-shaped capabilities pays for none of it.
 */
final class WorkerLoop
{
    private int $requestsHandled = 0;
    private bool $workerBooted = false;

    public function __construct(
        private readonly Context $context,
        private readonly WorkerRequestFactory $requestFactory,
        private readonly SuperglobalBridge $superglobals,
        private readonly OutputCapture $output,
        private readonly ErrorResponseFactory $errors,
        private readonly NativeSessionCookieBridge $sessionCookies,
        private readonly WorkerRuntimeCapabilities $capabilities,
        private readonly int $maxRequests = 0,
    ) {
    }

    public function capabilities(): WorkerRuntimeCapabilities
    {
        return $this->capabilities;
    }

    /** For SAPI-shaped runtimes, which have no request object of their own. */
    public function requestFromGlobals(): ServerRequestInterface
    {
        return $this->requestFactory->fromGlobals();
    }

    /**
     * Runs one request through the pipeline. Never throws: a throwable that
     * escapes becomes an error response, so a persistent worker survives a
     * broken request instead of dying with the pool.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->requestsHandled++;

        if (!$this->capabilities->populatesSuperglobals) {
            $this->superglobals->hydrate($request);
            // WebRequest::startup() empties $_GET/$_POST/$_COOKIE/$_FILES and
            // strips HTTP_* from $_SERVER when this attribute is truthy (it
            // defaults to true). That defends against register-globals-era
            // input leakage from a real SAPI; when we deliberately hydrated
            // those arrays a moment ago it would instead wipe them mid-request.
            // Clearing them is dehydrate()'s job at the request boundary.
            $request = $request->withAttribute('unset_input', false);
        }

        if (!$this->capabilities->sapiOutput) {
            $this->output->start();
        }

        try {
            $response = $this->context->handle($this->requestFactory->fromPsr($request));
        } catch (Throwable $e) {
            $response = $this->errors->fromThrowable($e, $request);
        } finally {
            $stray = $this->capabilities->sapiOutput ? '' : $this->output->finish();
        }

        if ($stray !== '') {
            $response = $this->appendStray($response, $stray);
        }

        if (!$this->capabilities->sapiOutput) {
            $response = $this->sessionCookies->apply($response);
        }

        return $response;
    }

    /**
     * Post-fork / first-request-in-this-process hook. Idempotent, so a runtime
     * that doesn't fork can call it unconditionally before its loop.
     *
     * A forking runtime (Swoole) starts its children *after* bootstrap has
     * already built the Context and possibly opened database sockets, so every
     * child would inherit the same connections and interleave on the wire.
     */
    public function bootWorker(): void
    {
        if ($this->workerBooted) {
            return;
        }
        $this->workerBooted = true;

        if (!$this->capabilities->sapiOutput) {
            $this->sessionCookies->disableNativeEmission();
        }

        if (!$this->capabilities->forksWorkers) {
            return;
        }

        WorkerManager::manageDatabaseConnections('close');
        try {
            $this->context->reset();
        } catch (Throwable $e) {
            Log::for($this)->error('[WorkerLoop] post-fork context reset failed: ' . $e->getMessage());
        }
    }

    /** Request boundary: clear anything that must not leak into the next request. */
    public function afterRequest(): void
    {
        if (!$this->capabilities->persistent) {
            return;
        }

        if (!$this->capabilities->populatesSuperglobals) {
            $this->superglobals->dehydrate();
        }

        WorkerManager::resetForNextRequest($this->context->getName());

        // Per-request-boundary hook for plugins holding worker-lifetime state
        // that needs flushing between requests -- no concrete plugin class is
        // named here; core's telemetry exporter registers its own listener.
        Events::emitLazy(
            WorkerRequestCompletedEvent::class,
            fn(): WorkerRequestCompletedEvent => new WorkerRequestCompletedEvent($this->context),
        );
    }

    /** False once the max-requests budget is spent; always true when unlimited. */
    public function shouldContinue(): bool
    {
        return $this->maxRequests <= 0 || $this->requestsHandled < $this->maxRequests;
    }

    public function requestsHandled(): int
    {
        return $this->requestsHandled;
    }

    /** For a runtime that catches a protocol-level throwable of its own. */
    public function renderError(Throwable $e, ?ServerRequestInterface $request = null): ResponseInterface
    {
        return $this->errors->fromThrowable($e, $request);
    }

    /** Graceful stop. */
    public function shutdown(): void
    {
        try {
            WorkerManager::shutdown();
        } catch (Throwable $e) {
            Log::for($this)->error('[WorkerLoop] shutdown failed: ' . $e->getMessage());
        }
    }

    /**
     * An SseStream body is written incrementally by the emitter and cannot be
     * concatenated onto, so stray output around one is logged and dropped
     * rather than corrupting the event framing.
     */
    private function appendStray(ResponseInterface $response, string $stray): ResponseInterface
    {
        $body = $response->getBody();
        if ($body instanceof SseStream) {
            Log::for($this)->notice(
                '[WorkerLoop] dropped ' . strlen($stray) . ' byte(s) of stray output around a streaming response; '
                . 'appending it would corrupt the SSE framing.'
            );
            return $response;
        }

        try {
            $applied = $this->output->apply($stray);
        } catch (Throwable $e) {
            return $this->errors->fromThrowable($e);
        }
        if ($applied === '') {
            return $response;
        }

        // Rebuilt rather than written into: a stream created from a string sits
        // rewound, so write() would overwrite the body instead of extending it.
        return $response->withBody(Stream::create((string) $body . $applied));
    }
}
