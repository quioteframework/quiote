<?php

namespace Quiote\Util;

/**
 * Helper to bridge configuration storage for form population between legacy
 * namespaced attributes and the PSR-7 attribute bag on WebRequest.
 */
final class FormPopulationConfig
{
    private const string LEGACY_NAMESPACE = 'org.quiote.filter.FormPopulationFilter';
    private const string ATTRIBUTE_KEY = 'org.quiote.filter.FormPopulationFilter';

    /**
     * Retrieve the current configuration map.
     */
    public static function get(mixed $request): array
    {
        if ($request === null) {
            return [];
        }
        // Legacy request with namespaced attributes
        if (method_exists($request, 'getAttributes') && self::supportsNamespacedAttributes($request)) {
            return (array) $request->getAttributes(self::LEGACY_NAMESPACE);
        }
        if (method_exists($request, 'getAttribute')) {
            $value = $request->getAttribute(self::ATTRIBUTE_KEY);
            return is_array($value) ? $value : (array) $value;
        }
        return [];
    }

    /**
     * Seed configuration defaults without overwriting previously provided values.
     */
    public static function seed(mixed $request, array $defaults): void
    {
        if ($request === null) {
            return;
        }
        $current = self::get($request);
        // Only fill missing keys with defaults
        foreach ($defaults as $key => $value) {
            if (!array_key_exists($key, $current)) {
                $current[$key] = $value;
            }
        }
        self::store($request, $current);
    }

    /**
     * Merge configuration overrides, allowing new values to replace existing ones.
     */
    public static function merge(mixed $request, array $overrides): void
    {
        if ($request === null) {
            return;
        }
        $current = self::get($request);
        foreach ($overrides as $key => $value) {
            $current[$key] = $value;
        }
        self::store($request, $current);
    }

    /**
     * Convenience helper to set a single scoped value.
     */
    public static function setScopedValue(mixed $request, string $key, mixed $value): void
    {
        self::merge($request, [$key => $value]);
    }

    /**
     * Retrieve a single scoped value with default fallback.
     */
    public static function getScopedValue(mixed $request, string $key, mixed $default = null): mixed
    {
        $config = self::get($request);
        return $config[$key] ?? $default;
    }

    private static function store(mixed $request, array $config): void
    {
        if ($request === null) {
            return;
        }
        // Legacy request supports namespaces
        if (self::supportsNamespacedAttributes($request)) {
            if (method_exists($request, 'removeAttributeNamespace')) {
                $request->removeAttributeNamespace(self::LEGACY_NAMESPACE);
            }
            if (method_exists($request, 'setAttributes')) {
                $request->setAttributes($config, self::LEGACY_NAMESPACE);
                return;
            }
        }
        if (method_exists($request, 'setAttribute')) {
            $request->setAttribute(self::ATTRIBUTE_KEY, $config);
            return;
        }
    }

    private static function supportsNamespacedAttributes(mixed $request): bool
    {
        return method_exists($request, 'getAttributeNamespaces') && method_exists($request, 'getAttributes') && method_exists($request, 'setAttributes');
    }
}
