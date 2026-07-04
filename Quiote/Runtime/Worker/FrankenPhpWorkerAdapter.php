<?php
namespace Quiote\Runtime\Worker;

use Quiote\Util\WorkerManager;

class FrankenPhpWorkerAdapter implements WorkerAdapterInterface
{
    public static function isSupported(): bool
    {
        return function_exists('frankenphp_handle_request');
    }

    public function run(callable $handle, ?callable $reset = null): void
    {
        while (frankenphp_handle_request(static fn() => $handle())) {
            if($reset) { $reset(); }
        }
    }
}
