<?php

declare(strict_types=1);

namespace Quiote\Runtime\Worker;

/**
 * What a {@see WorkerRuntimeInterface} can and cannot do, so the shared
 * {@see WorkerLoop} knows which compensations to install rather than every
 * runtime re-deciding.
 *
 * The distinction that matters most is $populatesSuperglobals + $sapiOutput:
 * a real SAPI (php-fpm, php -S, FrankenPHP) refills $_SERVER/$_GET/... per
 * request and lets header()/echo reach the client, while a CLI-hosted server
 * (RoadRunner, Swoole) does neither -- it hands over a request object and
 * takes a response object. Everything the loop does to bridge that gap
 * (superglobal hydration, output capture, native session-cookie
 * synthesis) keys off these two flags.
 */
final readonly class WorkerRuntimeCapabilities
{
    /**
     * @param bool $persistent            The process survives across requests, so per-request state must be reset.
     * @param bool $populatesSuperglobals The runtime fills $_SERVER/$_GET/$_POST/$_COOKIE/$_FILES itself.
     * @param bool $sapiOutput            echo/header()/http_response_code() reach the client.
     * @param bool $streaming             The response body can be flushed incrementally (SSE).
     * @param bool $forksWorkers          Worker processes are forked after bootstrap, so bootWorker() must run per child.
     */
    public function __construct(
        public bool $persistent,
        public bool $populatesSuperglobals,
        public bool $sapiOutput,
        public bool $streaming,
        public bool $forksWorkers,
    ) {
    }

    /**
     * The classic single-request SAPI shape: nothing persists, the SAPI owns
     * input and output. Also the correct answer for a FrankenPHP worker apart
     * from $persistent, hence the parameter.
     */
    public static function sapi(bool $persistent = false): self
    {
        return new self(
            persistent: $persistent,
            populatesSuperglobals: true,
            sapiOutput: true,
            streaming: true,
            forksWorkers: false,
        );
    }
}
