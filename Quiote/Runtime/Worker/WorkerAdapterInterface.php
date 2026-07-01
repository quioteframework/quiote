<?php
namespace Quiote\Runtime\Worker;

interface WorkerAdapterInterface
{
    /**
     * Run the worker loop (or single request) invoking $handle per request.
     * $handle(): bool should return true to continue, false to stop loop.
     * $reset(): void called after each successful handled request (for state reset in persistent workers).
     */
    public function run(callable $handle, ?callable $reset = null): void;
}
