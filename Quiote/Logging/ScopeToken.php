<?php

namespace Quiote\Logging;

/**
 * Handle to an active {@see LogContext} scope frame. Closing it (explicitly or
 * on destruction) pops exactly that frame. Idempotent.
 * Usage:
 *   $scope = LogContext::push(['userId' => 7]);
 *   try { ... } finally { $scope->close(); }
 * or simply let $scope go out of scope.
 */
final class ScopeToken
{
    private bool $closed = false;

    public function __construct(private readonly int $id) {}

    public function close(): void
    {
        if (!$this->closed) {
            $this->closed = true;
            LogContext::pop($this->id);
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
