<?php

declare(strict_types=1);

namespace Quiote\Runtime\Emitter;

use Psr\Http\Message\ResponseInterface;

/**
 * Sends a PSR-7 response back to whatever is on the other end of the current
 * worker runtime: the SAPI (header()/echo), a RoadRunner relay, a Swoole
 * response object.
 *
 * Each runtime owns its emitter, which is why the Kernel no longer constructs
 * one. Lifetime differs deliberately: the SAPI and RoadRunner emitters live as
 * long as the worker, while Swoole's wraps the per-request response object.
 */
interface ResponseEmitterInterface
{
    /**
     * Writes the status line, headers and body out through the host's channel.
     *
     * Called once per request by the runtime, after {@see \Quiote\Runtime\Worker\WorkerLoop::handle()}
     * has produced the response. An emitter reporting
     * {@see self::supportsStreaming()} must deliver an
     * {@see \Quiote\Http\Sse\SseStream} body chunk by chunk rather than
     * casting it to a string.
     */
    public function emit(ResponseInterface $response): void;

    /**
     * Whether this emitter can flush a body incrementally, i.e. whether a
     * {@see \Quiote\Http\Sse\SseStream} body will actually stream. When false,
     * `core.worker.sse_fallback` decides what happens instead.
     */
    public function supportsStreaming(): bool;
}
