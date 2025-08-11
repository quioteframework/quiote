<?php
namespace Agavi\Runtime\Worker;

use Agavi\Util\AgaviWorkerManager;

class FrankenPhpWorkerAdapter implements WorkerAdapterInterface
{
    public function __construct(private string $contextName) {}

    public static function isSupported(): bool
    {
        return function_exists('frankenphp_handle_request');
    }

    public function run(callable $handle, ?callable $reset = null): void
    {
        while (frankenphp_handle_request(static function() use ($handle) { return $handle(); })) {
            if($reset) { $reset(); }
        }
    }
}
