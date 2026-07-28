<?php

declare(strict_types=1);

namespace Quiote\Runtime\Worker;

use Quiote\Runtime\Emitter\ResponseEmitterInterface;
use Quiote\Runtime\Emitter\SapiEmitter;

/**
 * The classic one-request-per-process host: php-fpm, mod_php, `php -S`, and
 * the CLI when something calls Kernel::run() directly.
 *
 * Always supported, at the lowest possible detection priority, so
 * {@see WorkerRuntimeRegistry::detect()} can never come back empty-handed.
 */
final class SapiRuntime implements WorkerRuntimeInterface
{
    public function __construct(private readonly ResponseEmitterInterface $emitter = new SapiEmitter())
    {
    }

    public static function isSupported(): bool
    {
        return true;
    }

    public static function alias(): string
    {
        return 'sapi';
    }

    public static function detectionPriority(): int
    {
        return PHP_INT_MIN;
    }

    public function capabilities(): WorkerRuntimeCapabilities
    {
        return WorkerRuntimeCapabilities::sapi(persistent: false);
    }

    public function run(WorkerLoop $loop): void
    {
        $loop->bootWorker();
        $this->emitter->emit($loop->handle($loop->requestFromGlobals()));
        // No afterRequest(): the process is about to end, so there is no next
        // request to reset for and doing the work would only slow the response.
    }
}
