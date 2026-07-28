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
    public function emit(ResponseInterface $response): void;

    /**
     * Whether this emitter can flush a body incrementally, i.e. whether a
     * {@see \Quiote\Http\Sse\SseStream} body will actually stream. When false,
     * `core.worker.sse_fallback` decides what happens instead.
     */
    public function supportsStreaming(): bool;
}
