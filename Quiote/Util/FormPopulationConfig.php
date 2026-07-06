<?php

namespace Quiote\Util;

/**
 * Helper to bridge configuration storage for form population between legacy
 * namespaced attributes and the PSR-7 attribute bag on WebRequest.
 *
 * WebRequest is immutable, so seed()/merge()/setScopedValue()/store() return
 * the (possibly new) request instance; callers must capture and propagate
 * it. Legacy namespaced-attribute holders mutate in place and are returned
 * unchanged for API symmetry.
 */
final class FormPopulationConfig
{
    private const string LEGACY_NAMESPACE = 'org.quiote.filter.FormPopulationFilter';
    private const string ATTRIBUTE_KEY = 'org.quiote.filter.FormPopulationFilter';

    /**
     * Retrieve the current configuration map.
     * @return array<string, mixed>
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
     * @param array<string, mixed> $defaults
     */
    public static function seed(mixed $request, array $defaults): mixed
    {
        if ($request === null) {
            return $request;
        }
        $current = self::get($request);
        // Only fill missing keys with defaults
        foreach ($defaults as $key => $value) {
            if (!array_key_exists($key, $current)) {
                $current[$key] = $value;
            }
        }
        return self::store($request, $current);
    }

    /**
     * Merge configuration overrides, allowing new values to replace existing ones.
     * @param array<string, mixed> $overrides
     */
    public static function merge(mixed $request, array $overrides): mixed
    {
        if ($request === null) {
            return $request;
        }
        $current = self::get($request);
        foreach ($overrides as $key => $value) {
            $current[$key] = $value;
        }
        return self::store($request, $current);
    }

    /**
     * Convenience helper to set a single scoped value.
     */
    public static function setScopedValue(mixed $request, string $key, mixed $value): mixed
    {
        return self::merge($request, [$key => $value]);
    }

    /**
     * Retrieve a single scoped value with default fallback.
     */
    public static function getScopedValue(mixed $request, string $key, mixed $default = null): mixed
    {
        $config = self::get($request);
        return $config[$key] ?? $default;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function store(mixed $request, array $config): mixed
    {
        if ($request === null) {
            return $request;
        }
        // Legacy request supports namespaces
        if (self::supportsNamespacedAttributes($request)) {
            if (method_exists($request, 'removeAttributeNamespace')) {
                $request->removeAttributeNamespace(self::LEGACY_NAMESPACE);
            }
            if (method_exists($request, 'setAttributes')) {
                $request->setAttributes($config, self::LEGACY_NAMESPACE);
                return $request;
            }
        }
        if (method_exists($request, 'setAttribute')) {
            return $request->setAttribute(self::ATTRIBUTE_KEY, $config);
        }
        return $request;
    }

    private static function supportsNamespacedAttributes(mixed $request): bool
    {
        return method_exists($request, 'getAttributeNamespaces') && method_exists($request, 'getAttributes') && method_exists($request, 'setAttributes');
    }
}
