<?php

namespace Quiote\Logging;

use Psr\Log\LogLevel;

/**
 * Ordinal log level with minimum-level (>=) semantics.
 * Aligned to PSR-3 / RFC 5424 so a PSR-3 level string maps directly, with an
 * extra {@see Level::Trace} below Debug (Serilog "Verbose") that degrades to
 * "debug" on PSR-3 output. Higher value = more severe. A message passes a
 * threshold when `$message->value >= $threshold->value`.
 */
enum Level: int
{
    case Trace     = 50;   // Serilog "Verbose"; no PSR-3 equivalent -> "debug"
    case Debug     = 100;
    case Info      = 200;
    case Notice    = 250;
    case Warning   = 300;
    case Error     = 400;
    case Critical  = 500;
    case Alert     = 550;
    case Emergency = 600;

    /**
     * Map a PSR-3 {@see LogLevel} string to a Level.
     * @throws \Psr\Log\InvalidArgumentException on an unknown level (PSR-3 requirement).
     */
    public static function fromPsr(string $psrLevel): self
    {
        return match ($psrLevel) {
            LogLevel::DEBUG     => self::Debug,
            LogLevel::INFO      => self::Info,
            LogLevel::NOTICE    => self::Notice,
            LogLevel::WARNING   => self::Warning,
            LogLevel::ERROR     => self::Error,
            LogLevel::CRITICAL  => self::Critical,
            LogLevel::ALERT     => self::Alert,
            LogLevel::EMERGENCY => self::Emergency,
            default             => throw new \Psr\Log\InvalidArgumentException(
                sprintf('Unknown PSR-3 log level "%s".', $psrLevel)
            ),
        };
    }

    /**
     * The PSR-3 level string for this level. Trace has no PSR-3 equivalent and
     * degrades to "debug".
     */
    public function toPsr(): string
    {
        return match ($this) {
            self::Trace, self::Debug => LogLevel::DEBUG,
            self::Info               => LogLevel::INFO,
            self::Notice             => LogLevel::NOTICE,
            self::Warning            => LogLevel::WARNING,
            self::Error              => LogLevel::ERROR,
            self::Critical           => LogLevel::CRITICAL,
            self::Alert              => LogLevel::ALERT,
            self::Emergency          => LogLevel::EMERGENCY,
        };
    }

    /**
     * Parse a case-insensitive level name for configuration
     * (e.g. LOG_LEVEL=info). Accepts "warn" as an alias for "warning".
     * @throws \InvalidArgumentException on an unknown name.
     */
    public static function fromName(string $name): self
    {
        return match (strtolower(trim($name))) {
            'trace', 'verbose'     => self::Trace,
            'debug'                => self::Debug,
            'info', 'information'  => self::Info,
            'notice'               => self::Notice,
            'warn', 'warning'      => self::Warning,
            'error', 'err'         => self::Error,
            'critical', 'crit'     => self::Critical,
            'alert'                => self::Alert,
            'emergency', 'fatal'   => self::Emergency,
            default                => throw new \InvalidArgumentException(
                sprintf('Unknown log level name "%s".', $name)
            ),
        };
    }

    /**
     * Lowercase canonical name used in structured output (e.g. "trace", "warning").
     */
    public function label(): string
    {
        return strtolower($this->name);
    }

    /**
     * Whether a message at $this level passes the given minimum threshold.
     */
    public function passes(Level $threshold): bool
    {
        return $this->value >= $threshold->value;
    }
}
