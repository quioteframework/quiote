<?php

declare(strict_types=1);

namespace Quiote\Replay\Recording;

/**
 * Every registered {@see EffectSource}, for {@see RecorderMiddleware} to
 * activate/deactivate around one request. A plain list, not a driver-alias
 * map like {@see \Quiote\Replay\Store\CassetteStoreRegistry}: more than one
 * process-scoped-observer-style driver could plausibly be active in the same
 * app (unlikely, but nothing here assumes exactly one).
 */
final class EffectSourceRegistry
{
    /** @var list<EffectSource> */
    private static array $sources = [];

    private function __construct()
    {
    }

    public static function register(EffectSource $source): void
    {
        self::$sources[] = $source;
    }

    /** @return list<EffectSource> */
    public static function all(): array
    {
        return self::$sources;
    }

    /** Test isolation. */
    public static function reset(): void
    {
        self::$sources = [];
    }
}
