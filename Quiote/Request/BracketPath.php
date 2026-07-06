<?php

declare(strict_types=1);

namespace Quiote\Request;

/**
 * Stateless resolution of legacy bracket-path parameter names (e.g.
 * "data[0][Application]") against a plain nested array.
 */
final class BracketPath
{
    private function __construct()
    {
    }

    /**
     * Manual, conservative bracket path resolution for nested parameters like
     * foo[0][bar]. Returns null if any segment is missing. Does not support
     * empty brackets [] append semantics for safety.
     * @param array<array-key, mixed> $rootArray
     */
    public static function resolve(string $path, array $rootArray): mixed
    {
        $firstBracket = strpos($path, '[');
        if ($firstBracket === false) {
            return $rootArray[$path] ?? null;
        }
        $rootKey = substr($path, 0, $firstBracket);
        if ($rootKey === '' || !array_key_exists($rootKey, $rootArray)) {
            return null;
        }
        $current = $rootArray[$rootKey];
        if (!is_array($current)) {
            return null;
        }
        if (!preg_match_all('/\[([^\]]*)\]/', $path, $matches)) {
            return null;
        }
        foreach ($matches[1] as $seg) {
            if ($seg === '' || !is_array($current) || !array_key_exists($seg, $current)) {
                return null;
            }
            $current = $current[$seg];
        }
        return $current;
    }
}
