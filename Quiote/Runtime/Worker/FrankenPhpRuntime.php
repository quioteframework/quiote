<?php

declare(strict_types=1);

namespace Quiote\Runtime\Worker;

use Closure;
use Quiote\Runtime\Emitter\ResponseEmitterInterface;
use Quiote\Runtime\Emitter\SapiEmitter;

/**
 * FrankenPHP worker mode: a persistent process that parks in
 * frankenphp_handle_request() and gets its superglobals refilled per request.
 *
 * FrankenPHP is a real SAPI, so this is the one persistent runtime that keeps
 * every SAPI-shaped assumption -- header()/echo work, superglobals arrive
 * populated, flush() streams -- and the loop's off-SAPI compensations stay
 * switched off.
 */
final class FrankenPhpRuntime implements WorkerRuntimeInterface
{
    /** @var Closure(callable): bool */
    private Closure $handleRequest;

    /**
     * @param (Closure(callable): bool)|null $handleRequest Injectable so the loop
     *        is testable without FrankenPHP; defaults to the real extension function.
     */
    public function __construct(
        ?Closure $handleRequest = null,
        private readonly ResponseEmitterInterface $emitter = new SapiEmitter(),
    ) {
        $this->handleRequest = $handleRequest ?? static fn(callable $cb): bool => \frankenphp_handle_request($cb);
    }

    /**
     * True when the FrankenPHP extension is present, detected by the existence
     * of frankenphp_handle_request(). That function only exists inside a
     * FrankenPHP worker process, so no opt-in environment marker is needed.
     */
    public static function isSupported(): bool
    {
        return function_exists('frankenphp_handle_request');
    }

    /** The registry alias: "frankenphp". */
    public static function alias(): string
    {
        return 'frankenphp';
    }

    /**
     * Detection priority 100 — well above {@see SapiRuntime}'s PHP_INT_MIN, so
     * a FrankenPHP worker always wins over the plain SAPI fallback.
     */
    public static function detectionPriority(): int
    {
        return 100;
    }

    /** SAPI-shaped and persistent: superglobals and output work natively, across many requests. */
    public function capabilities(): WorkerRuntimeCapabilities
    {
        return WorkerRuntimeCapabilities::sapi(persistent: true);
    }

    /**
     * Boots the worker, then serves requests from frankenphp_handle_request().
     *
     * Each iteration builds the request from the refilled superglobals, runs it
     * through the loop and emits the response; {@see WorkerLoop::afterRequest()}
     * runs in a `finally` so a failed emission still resets request state. The
     * loop ends when FrankenPHP asks the worker to stop or the max-requests
     * budget is spent, and {@see WorkerLoop::shutdown()} is called on the way
     * out.
     */
    public function run(WorkerLoop $loop): void
    {
        $loop->bootWorker();

        $handler = function () use ($loop): void {
            $this->emitter->emit($loop->handle($loop->requestFromGlobals()));
        };

        do {
            // afterRequest() in a finally, matching SwooleRuntime/RoadRunnerRuntime:
            // WorkerLoop::handle() catches throwables from the pipeline, but the
            // emit() inside $handler sits outside that try, so a broken pipe or a
            // client disconnect mid-stream would otherwise skip the request-boundary
            // reset entirely and leak this request's state into the next one.
            try {
                $keepRunning = ($this->handleRequest)($handler);
            } finally {
                $loop->afterRequest();
            }
        } while ($keepRunning && $loop->shouldContinue());

        $loop->shutdown();
    }
}
