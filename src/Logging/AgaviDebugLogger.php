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
     * @param string|null $level Log level (debug, info, warn, error, fatal); defaults to warn
     */
    public static function doLog(string $message, ?AgaviContext $context = null, ?string $level = "warn"): void
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
            $method = match ($level) {
                'debug' => 'debug',
                'info' => 'info',
                'error' => 'error',
                'warn' => 'warn',
                'fatal' => 'fatal',
                default => 'warn',
            };
            $ctx?->getLoggerManager()?->getLogger()?->$method($message);
        } catch (\Throwable) {
            // Swallow logging failures to preserve original control flow.
            @error_log("Could not get debug logger");
        }
    }

    public static function debug(string $message, ?AgaviContext $context = null): void
    {
        self::doLog($message, $context, "debug");
    }

    public static function info(string $message, ?AgaviContext $context = null): void
    {
        self::doLog($message, $context, "info");
    }

    public static function error(string $message, ?AgaviContext $context = null): void
    {
        self::doLog($message, $context, "error");
    }

    public static function warn(string $message, ?AgaviContext $context = null): void
    {
        self::doLog($message, $context, "warn");
    }

    public static function fatal(string $message, ?AgaviContext $context = null): void
    {
        self::doLog($message, $context, "fatal");
    }
    

}
