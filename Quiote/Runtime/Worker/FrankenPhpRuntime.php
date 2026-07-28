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

    public static function isSupported(): bool
    {
        return function_exists('frankenphp_handle_request');
    }

    public static function alias(): string
    {
        return 'frankenphp';
    }

    public static function detectionPriority(): int
    {
        return 100;
    }

    public function capabilities(): WorkerRuntimeCapabilities
    {
        return WorkerRuntimeCapabilities::sapi(persistent: true);
    }

    public function run(WorkerLoop $loop): void
    {
        $loop->bootWorker();

        $handler = function () use ($loop): void {
            $this->emitter->emit($loop->handle($loop->requestFromGlobals()));
        };

        do {
            $keepRunning = ($this->handleRequest)($handler);
            $loop->afterRequest();
        } while ($keepRunning && $loop->shouldContinue());

        $loop->shutdown();
    }
}
