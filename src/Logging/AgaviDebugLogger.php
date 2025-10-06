<?php

namespace Agavi\Logging;

use Agavi\AgaviContext;

/**
 * Utility logger used for vendor-level debug statements.
 * Attempts to route diagnostics through the configured Agavi logger if available.
 */
final class AgaviDebugLogger
{
    private function __construct()
    {
    }

    /**
     * Emit a debug-level message if a logger is available.
     *
     * @param string $message Message to log
     * @param AgaviContext|null $context Optional context to reuse for logger lookup
     */
    public static function debug(string $message, ?AgaviContext $context = null): void
    {
        try {
            $ctx = $context;
            if ($ctx === null) {
                try {
                    $ctx = AgaviContext::getInstance();
                } catch (\Throwable) {
                    $ctx = null;
                }
            }
            $ctx?->getLoggerManager()?->getLogger()?->debug($message);
        } catch (\Throwable) {
            // Swallow logging failures to preserve original control flow.
        }
    }
}
