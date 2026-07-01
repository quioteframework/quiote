<?php

namespace Agavi\Logging;

use Agavi\AgaviContext;

/**
 * Utility logger used for vendor-level debug statements.
 * Attempts to route diagnostics through the configured Agavi logger if available.
 */
final class AgaviDebugLogger
{
    /**
     * Memoized "is DEBUG level enabled" flag. Null until first successfully
     * resolved against a configured logger. In FrankenPHP worker mode the log
     * level is fixed for the process lifetime, so this is computed at most once
     * and then reused across every request — avoiding a context/logger-manager
     * lookup (and, at guarded call sites, expensive message construction) on the
     * hot path when debug logging is off.
     */
    private static ?bool $debugEnabled = null;

    private function __construct()
    {
    }

    /**
     * Whether DEBUG-level logging is actually enabled.
     *
     * Call this to guard expensive debug-message construction:
     *   if (AgaviDebugLogger::isDebugEnabled()) { AgaviDebugLogger::debug(...expensive...); }
     *
     * Returns false (without memoizing) while no logger is resolvable yet — e.g.
     * during early bootstrap — so a genuinely enabled level is not cached away.
     *
     * @param AgaviContext|null $context Optional context to reuse for logger lookup
     */
    public static function isDebugEnabled(?AgaviContext $context = null): bool
    {
        if (self::$debugEnabled !== null) {
            return self::$debugEnabled;
        }
        try {
            $ctx = $context ?? AgaviContext::getInstance();
            $logger = $ctx?->getLoggerManager()?->getLogger();
            if ($logger === null || !method_exists($logger, 'getLevel')) {
                return false; // logger not ready — do not memoize
            }
            return self::$debugEnabled = (($logger->getLevel() & \Agavi\Logging\AgaviILogger::DEBUG) !== 0);
        } catch (\Throwable) {
            return false;
        }
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
        // Fast path: skip the context/logger-manager lookup entirely for debug
        // messages when DEBUG is disabled (the common production case).
        if ($level === "debug" && !self::isDebugEnabled($context)) {
            return;
        }
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
