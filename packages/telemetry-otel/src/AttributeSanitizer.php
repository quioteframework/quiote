<?php

namespace Quiote\Telemetry;

/**
 * Validates arbitrary attribute maps against the shape the OTel SDK's own
 * span/meter APIs require: non-empty-string keys, and values that are
 * `array|bool|float|int|string|null` (arrays homogeneous, one scalar type
 * throughout). Instrumentation call sites hand in whatever a caller passed
 * as `mixed`; this is where that gets enforced, once, rather than every
 * handle re-deriving its own idea of what's acceptable.
 */
final class AttributeSanitizer
{
    /**
     * @param array<array-key, mixed> $attributes
     * @return array<non-empty-string, array<int, bool|float|int|string>|bool|float|int|string|null>
     */
    public static function sanitize(array $attributes): array
    {
        $sanitized = [];
        foreach ($attributes as $key => $value) {
            [$sanitizedKey, $sanitizedValue] = self::sanitizeEntry($key, $value);
            $sanitized[$sanitizedKey] = $sanitizedValue;
        }
        return $sanitized;
    }

    /**
     * @return array{0: non-empty-string, 1: array<int, bool|float|int|string>|bool|float|int|string|null}
     */
    public static function sanitizeEntry(int|string $key, mixed $value): array
    {
        $key = (string) $key;
        if ($key === '') {
            throw new \InvalidArgumentException('Telemetry attribute keys must be non-empty strings.');
        }

        if ($value === null || is_bool($value) || is_float($value) || is_int($value) || is_string($value)) {
            return [$key, $value];
        }

        if (is_array($value)) {
            $elements = [];
            $elementType = null;
            foreach ($value as $element) {
                if (!is_bool($element) && !is_float($element) && !is_int($element) && !is_string($element)) {
                    throw new \InvalidArgumentException(sprintf(
                        'Telemetry attribute "%s" contains an array with an unsupported element type "%s".',
                        $key,
                        get_debug_type($element),
                    ));
                }
                $currentType = get_debug_type($element);
                $elementType ??= $currentType;
                if ($currentType !== $elementType) {
                    throw new \InvalidArgumentException(sprintf(
                        'Telemetry attribute "%s" mixes array element types ("%s" and "%s"); attribute arrays must be homogeneous.',
                        $key,
                        $elementType,
                        $currentType,
                    ));
                }
                $elements[] = $element;
            }
            return [$key, $elements];
        }

        throw new \InvalidArgumentException(sprintf(
            'Telemetry attribute "%s" has an unsupported value type "%s"; expected array|bool|float|int|string|null.',
            $key,
            get_debug_type($value),
        ));
    }
}
