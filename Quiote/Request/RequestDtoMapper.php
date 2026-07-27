<?php
namespace Quiote\Request;

use BackedEnum;
use DateTimeImmutable;
use JsonException;
use Quiote\Request\Compiler\RequestDtoProperty;
use RuntimeException;
use Throwable;

/**
 * Constructs a #[MapRequest] DTO instance from an already-validated
 * WebRequest. Must only be called after ValidationMiddleware's
 * ValidationManager::execute() has passed -- property names are only
 * readable via WebRequest::getParameter() once whitelisted by their
 * RequestDtoScanner-registered validators (see
 * Quiote\Action\Action::registerValidators()).
 *
 * Scalar casting/normalization (int/float parsing, boolean literalization)
 * has already happened inside NumberValidator/BooleanValidator during
 * validation, which persist the cast value back into the request's runtime
 * parameters -- this class mostly passes values through, only handling the
 * shapes those validators don't already normalize (JSON-encoded arrays,
 * DateTimeImmutable, backed enums).
 * @since      1.0.0
 */
final class RequestDtoMapper
{
    public static function map(WebRequest $request, string $dtoClass): object
    {
        $definition = RequestDtoRegistry::definitionFor($dtoClass);

        $arguments = [];
        foreach ($definition->properties as $property) {
            $arguments[$property->name] = self::resolveValue($request, $property);
        }

        return new $dtoClass(...$arguments);
    }

    private static function resolveValue(WebRequest $request, RequestDtoProperty $property): mixed
    {
        if (!$request->hasParameter($property->name)) {
            if ($property->hasDefault) {
                return $property->defaultValue;
            }
            if ($property->nullable) {
                return null;
            }
            throw new RuntimeException('Required #[MapRequest] property "' . $property->name . '" was absent after validation passed; this indicates the registered validator did not actually enforce required=true.');
        }

        $value = $request->getParameter($property->name);
        if ($value === null) {
            return null;
        }

        return match ($property->kind) {
            'string' => self::toStringValue($value, $property->name),
            'int' => self::toIntValue($value, $property->name),
            'float' => self::toFloatValue($value, $property->name),
            'bool' => is_bool($value) ? $value : (bool) $value,
            'array' => self::toArray($value, $property->name),
            'datetime' => self::toDateTime($value, $property->name),
            'enum' => self::toEnum($value, $property),
        };
    }

    private static function toStringValue(mixed $value, string $propertyName): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        throw new RuntimeException('Property "' . $propertyName . '" resolved to a non-scalar value; cannot cast to string.');
    }

    private static function toIntValue(mixed $value, string $propertyName): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_scalar($value)) {
            return (int) $value;
        }
        throw new RuntimeException('Property "' . $propertyName . '" resolved to a non-scalar value; cannot cast to int.');
    }

    private static function toFloatValue(mixed $value, string $propertyName): float
    {
        if (is_float($value)) {
            return $value;
        }
        if (is_scalar($value)) {
            return (float) $value;
        }
        throw new RuntimeException('Property "' . $propertyName . '" resolved to a non-scalar value; cannot cast to float.');
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function toArray(mixed $value, string $propertyName): array
    {
        if (is_array($value)) {
            return $value;
        }
        $raw = self::toStringValue($value, $propertyName);
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Property "' . $propertyName . '" was validated as JSON but failed to decode: ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Property "' . $propertyName . '" decoded to a non-array JSON value.');
        }
        return $decoded;
    }

    private static function toDateTime(mixed $value, string $propertyName): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        $raw = self::toStringValue($value, $propertyName);
        try {
            return new DateTimeImmutable($raw);
        } catch (Throwable $e) {
            throw new RuntimeException('Property "' . $propertyName . '" was validated as a date/time but failed to parse: ' . $e->getMessage(), 0, $e);
        }
    }

    private static function toEnum(mixed $value, RequestDtoProperty $property): BackedEnum
    {
        $enumClass = $property->enumClass;
        if ($enumClass === null || !is_a($enumClass, BackedEnum::class, true)) {
            throw new RuntimeException('Property "' . $property->name . '" has kind "enum" but no valid BackedEnum enumClass was recorded.');
        }
        if ($value instanceof $enumClass) {
            return $value;
        }
        if (!is_string($value) && !is_int($value)) {
            throw new RuntimeException('Property "' . $property->name . '" was validated as enum "' . $enumClass . '" but its raw value is not a string or int.');
        }
        return $enumClass::from($value);
    }
}
