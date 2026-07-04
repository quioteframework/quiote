<?php

namespace Quiote\Telemetry;

use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;

/**
 * Process-global store of telemetry configuration AND (once
 * {@see TelemetryBootstrap} has run) the worker-lifetime tracer/meter
 * provider singletons. Deliberately free of any dependency on Config/the
 * context/bootstrap (mirrors {@see \Quiote\Logging\LogRegistry}, which plays
 * the same role for sinks) so it can be configured in index.php before
 * Kernel::run() and is safe to call during bootstrap itself.
 *
 * The `TracerProviderInterface`/`MeterProviderInterface` type hints below
 * reference optional open-telemetry/* classes — those packages are
 * `suggest`-only, never a hard dependency. PHP resolves
 * parameter/return types lazily at call time, not at class-load time, so this
 * file loads safely even when the SDK isn't installed; {@see setProviders()}
 * and the accessors below are simply never called in that case (guarded by
 * {@see TelemetryBootstrap}'s own `class_exists()` check).
 */
final class TraceRegistry
{
    private static bool $enabled = false;

    private static ?TracerProviderInterface $tracerProvider = null;
    private static ?MeterProviderInterface $meterProvider = null;
    private static ?TracerInterface $tracer = null;
    private static ?MeterInterface $meter = null;
    private static ?OtelMeterHandle $meterHandle = null;

    /** @var array<string,bool> category-prefix => enabled */
    private static array $categoryEnabled = [];
    private static bool $defaultCategoryEnabled = true;
    /** @var array<string,bool> memoized resolved enabled-state per exact category */
    private static array $resolvedCategories = [];

    public static function setEnabled(bool $enabled): void
    {
        self::$enabled = $enabled;
    }

    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    public static function setCategoryEnabled(string $categoryPrefix, bool $enabled): void
    {
        self::$categoryEnabled[$categoryPrefix] = $enabled;
        self::$resolvedCategories = [];
    }

    /** @param array<string,bool> $map category-prefix => enabled */
    public static function setCategories(array $map): void
    {
        foreach ($map as $prefix => $enabled) {
            self::$categoryEnabled[$prefix] = $enabled;
        }
        self::$resolvedCategories = [];
    }

    public static function setDefaultCategoryEnabled(bool $enabled): void
    {
        self::$defaultCategoryEnabled = $enabled;
        self::$resolvedCategories = [];
    }

    /**
     * Whether spans in $category should be recorded. Deliberately NOT the same algorithm as
     * {@see \Quiote\Logging\LogRegistry::resolveLevel()}: logging lets a more
     * specific child override its parent (longest-prefix-wins); this is a
     * cascade instead — a disabled ancestor (or the category itself) wins
     * unconditionally, so a descendant's own explicit `true` cannot re-enable
     * it. That's what makes disabling a category a real "turn off this whole
     * subtree" kill switch rather than an exercise in enumerating every leaf.
     * Only once nothing on the chain is disabled does longest-prefix matching
     * against explicit `true` entries apply, falling back to
     * {@see $defaultCategoryEnabled}. Memoized per exact category string.
     */
    public static function isCategoryEnabled(string $category): bool
    {
        if (isset(self::$resolvedCategories[$category])) {
            return self::$resolvedCategories[$category];
        }

        foreach (self::$categoryEnabled as $prefix => $enabled) {
            if ($enabled === false && ($category === $prefix || str_starts_with($category, $prefix . '.'))) {
                return self::$resolvedCategories[$category] = false;
            }
        }

        $bestLen = -1;
        $matchedTrue = false;
        foreach (self::$categoryEnabled as $prefix => $enabled) {
            if ($enabled === true && ($category === $prefix || str_starts_with($category, $prefix . '.'))) {
                $len = strlen($prefix);
                if ($len > $bestLen) {
                    $bestLen = $len;
                    $matchedTrue = true;
                }
            }
        }

        return self::$resolvedCategories[$category] = $matchedTrue ? true : self::$defaultCategoryEnabled;
    }

    /**
     * Install the worker-lifetime provider singletons. Called exactly once per
     * worker by {@see TelemetryBootstrap::configureFromConfig()}.
     */
    public static function setProviders(TracerProviderInterface $tracerProvider, MeterProviderInterface $meterProvider): void
    {
        self::$tracerProvider = $tracerProvider;
        self::$meterProvider = $meterProvider;
        self::$tracer = null;
        self::$meter = null;
        self::$meterHandle = null;
    }

    public static function hasRealProvider(): bool
    {
        return self::$tracerProvider !== null;
    }

    public static function tracerProvider(): ?TracerProviderInterface
    {
        return self::$tracerProvider;
    }

    public static function meterProvider(): ?MeterProviderInterface
    {
        return self::$meterProvider;
    }

    /** The single shared Tracer instance for the worker's lifetime, or null if unconfigured. */
    public static function tracer(): ?TracerInterface
    {
        if (self::$tracerProvider === null) {
            return null;
        }
        return self::$tracer ??= self::$tracerProvider->getTracer('quiote');
    }

    /** The single shared Meter instance for the worker's lifetime, or null if unconfigured. */
    public static function meter(): ?MeterInterface
    {
        if (self::$meterProvider === null) {
            return null;
        }
        return self::$meter ??= self::$meterProvider->getMeter('quiote');
    }

    /**
     * The single shared {@see OtelMeterHandle} for the worker's lifetime —
     * cached here (rather than rebuilt per {@see Trace::metrics()} call) so its
     * internal per-instrument-name cache (histograms/counters/gauges) survives
     * across calls instead of recreating SDK instrument objects every time.
     */
    public static function meterHandle(): ?OtelMeterHandle
    {
        $meter = self::meter();
        if ($meter === null) {
            return null;
        }
        return self::$meterHandle ??= new OtelMeterHandle($meter);
    }

    /**
     * Reset all configuration and drop the provider singletons. For test
     * isolation/reconfiguration (simulating a fresh worker); not used on the
     * request path.
     */
    public static function reset(): void
    {
        self::$enabled = false;
        self::$tracerProvider = null;
        self::$meterProvider = null;
        self::$tracer = null;
        self::$meter = null;
        self::$meterHandle = null;
        self::$categoryEnabled = [];
        self::$defaultCategoryEnabled = true;
        self::$resolvedCategories = [];
    }
}
