<?php
namespace Quiote\Request\Compiler;

use BackedEnum;
use DateTimeImmutable;
use InvalidArgumentException;
use Quiote\Context;
use Quiote\Request\Attribute\Constraint\BooleanType;
use Quiote\Request\Attribute\Constraint\Choice;
use Quiote\Request\Attribute\Constraint\DateTimeType;
use Quiote\Request\Attribute\Constraint\Email;
use Quiote\Request\Attribute\Constraint\JsonType;
use Quiote\Request\Attribute\Constraint\NotBlank;
use Quiote\Request\Attribute\Constraint\Range;
use Quiote\Request\Attribute\Constraint\Regexp;
use Quiote\Request\Attribute\Constraint\StringLength;
use Quiote\Request\Attribute\MapRequest;
use Quiote\Validator\Compiler\Runtime\ValidatorBuilder;
use Quiote\Validator\Compiler\Runtime\ValidatorSpec;
use Quiote\Validator\DateTimeValidator;
use Quiote\Validator\IValidatorContainer;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Reflects a #[MapRequest] DTO class exactly once (results are cached by
 * RequestDtoRegistry) to produce two independent things from the same walk
 * of its constructor-promoted properties:
 *
 *  - registerValidators(): translates each property's Quiote\Request\Attribute\Constraint\*
 *    attributes into the same ValidatorBuilder fluent calls a developer would
 *    write by hand in Action::register{Method}Validators() (see
 *    FluentValidatorAction) -- registering real Validator objects on the
 *    action's ValidationManager, so DTO-derived constraints get identical
 *    ValidationReport/ProblemDetails failure handling, and remain visible to
 *    ActionToolScanner::toValidatorNodes() for MCP JSON Schema derivation.
 *  - scan(): a pure RequestDtoDefinition used by RequestDtoMapper to
 *    instantiate the DTO once validation has passed.
 *
 * A property without any constraint attribute still gets a minimal
 * type-inferred validator registered (see registerInferredValidator()) --
 * this is not optional decoration, it's what gets the property's argument
 * name onto WebRequest's strict-validation whitelist at all (see
 * ValidationManager::execute()'s whitelist-from-registered-arguments step).
 * @since      1.0.0
 */
final class RequestDtoScanner
{
    /**
     * $class is an arbitrary reflected type name, not necessarily an
     * existing class -- verified here via class_exists(), not assumed by
     * the parameter type.
     */
    public static function isMapRequestDto(string $class): bool
    {
        if (!class_exists($class)) {
            return false;
        }
        return (new ReflectionClass($class))->getAttributes(MapRequest::class) !== [];
    }

    public static function scan(string $dtoClass): RequestDtoDefinition
    {
        if (!class_exists($dtoClass)) {
            throw new InvalidArgumentException($dtoClass . ' is not a known class.');
        }
        $reflection = new ReflectionClass($dtoClass);
        if ($reflection->getAttributes(MapRequest::class) === []) {
            throw new InvalidArgumentException($dtoClass . ' is not annotated with #[MapRequest].');
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            throw new InvalidArgumentException($dtoClass . ' has no constructor; #[MapRequest] DTOs must declare constructor-promoted properties.');
        }

        $properties = [];
        foreach ($constructor->getParameters() as $parameter) {
            $properties[] = self::scanParameter($parameter, $dtoClass);
        }

        return new RequestDtoDefinition($dtoClass, $properties);
    }

    public static function registerValidators(
        string $dtoClass,
        IValidatorContainer $validationManager,
        Context $context,
        ?string $method = null,
    ): void {
        if (!class_exists($dtoClass)) {
            throw new InvalidArgumentException($dtoClass . ' is not a known class.');
        }
        $reflection = new ReflectionClass($dtoClass);
        if ($reflection->getAttributes(MapRequest::class) === []) {
            throw new InvalidArgumentException($dtoClass . ' is not annotated with #[MapRequest].');
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            throw new InvalidArgumentException($dtoClass . ' has no constructor; #[MapRequest] DTOs must declare constructor-promoted properties.');
        }

        $builder = ValidatorBuilder::on($validationManager, $context, $method);
        foreach ($constructor->getParameters() as $parameter) {
            self::registerParameterValidators($builder, $parameter, $dtoClass);
        }
    }

    private static function scanParameter(ReflectionParameter $parameter, string $dtoClass): RequestDtoProperty
    {
        $name = $parameter->getName();
        $type = $parameter->getType();
        if (!$type instanceof ReflectionNamedType) {
            throw new InvalidArgumentException($dtoClass . '::$' . $name . ' must declare a single named type; union/intersection/untyped properties are not supported by #[MapRequest].');
        }

        $nullable = $type->allowsNull();
        $hasDefault = $parameter->isDefaultValueAvailable();
        $defaultValue = $hasDefault ? $parameter->getDefaultValue() : null;
        $typeName = $type->getName();

        [$kind, $enumClass] = self::classifyType($typeName, $dtoClass, $name);

        return new RequestDtoProperty($name, $kind, $nullable, $hasDefault, $defaultValue, $enumClass);
    }

    /**
     * @return array{0: 'string'|'int'|'float'|'bool'|'array'|'datetime'|'enum', 1: ?class-string}
     */
    private static function classifyType(string $typeName, string $dtoClass, string $propertyName): array
    {
        return match (true) {
            $typeName === 'string' => ['string', null],
            $typeName === 'int' => ['int', null],
            $typeName === 'float' => ['float', null],
            $typeName === 'bool' => ['bool', null],
            $typeName === 'array' => ['array', null],
            $typeName === DateTimeImmutable::class => ['datetime', null],
            enum_exists($typeName) && is_subclass_of($typeName, BackedEnum::class) => ['enum', $typeName],
            default => throw new InvalidArgumentException($dtoClass . '::$' . $propertyName . ' has unsupported type "' . $typeName . '"; #[MapRequest] supports string/int/float/bool/array/DateTimeImmutable/backed enum.'),
        };
    }

    private static function registerParameterValidators(ValidatorBuilder $builder, ReflectionParameter $parameter, string $dtoClass): void
    {
        $property = self::scanParameter($parameter, $dtoClass);
        $required = $property->isRequired();

        $notBlank = self::firstAttribute($parameter, NotBlank::class);
        $stringLength = self::firstAttribute($parameter, StringLength::class);
        $range = self::firstAttribute($parameter, Range::class);
        $email = self::firstAttribute($parameter, Email::class);
        $choice = self::firstAttribute($parameter, Choice::class);
        $regexp = self::firstAttribute($parameter, Regexp::class);
        $booleanType = self::firstAttribute($parameter, BooleanType::class);
        $jsonType = self::firstAttribute($parameter, JsonType::class);
        $dateTimeType = self::firstAttribute($parameter, DateTimeType::class);

        $registeredAny = false;

        if ($notBlank !== null) {
            self::withMessage($builder->isNotEmpty($property->name, required: $required), $notBlank->message);
            $registeredAny = true;
        }
        if ($stringLength !== null) {
            $spec = $builder->string($property->name, required: $required);
            if ($stringLength->min !== null) {
                $spec->minLength($stringLength->min);
            }
            if ($stringLength->max !== null) {
                $spec->maxLength($stringLength->max);
            }
            self::withMessage($spec, $stringLength->message);
            $registeredAny = true;
        }
        if ($range !== null) {
            $spec = $builder->number($property->name, required: $required);
            self::applyNumberType($spec, $property);
            if ($range->min !== null) {
                $spec->min($range->min);
            }
            if ($range->max !== null) {
                $spec->max($range->max);
            }
            self::withMessage($spec, $range->message);
            $registeredAny = true;
        }
        if ($email !== null) {
            self::withMessage($builder->email($property->name, required: $required), $email->message);
            $registeredAny = true;
        }
        if ($choice !== null) {
            self::withMessage($builder->enum($property->name, $choice->values, required: $required), $choice->message);
            $registeredAny = true;
        }
        if ($regexp !== null) {
            self::withMessage($builder->regex($property->name, $regexp->pattern, $regexp->match, required: $required), $regexp->message);
            $registeredAny = true;
        }
        if ($booleanType !== null) {
            self::withMessage($builder->boolean($property->name, required: $required), $booleanType->message);
            $registeredAny = true;
        }
        if ($jsonType !== null) {
            self::withMessage($builder->json($property->name, required: $required), $jsonType->message);
            $registeredAny = true;
        }
        if ($dateTimeType !== null) {
            self::withMessage($builder->raw(DateTimeValidator::class, [$property->name], ['required' => $required]), $dateTimeType->message);
            $registeredAny = true;
        }

        if (!$registeredAny) {
            self::registerInferredValidator($builder, $property, $required);
        }
    }

    private static function registerInferredValidator(ValidatorBuilder $builder, RequestDtoProperty $property, bool $required): void
    {
        match ($property->kind) {
            'string' => $builder->string($property->name, required: $required),
            'int' => self::applyNumberType($builder->number($property->name, required: $required), $property),
            'float' => self::applyNumberType($builder->number($property->name, required: $required), $property),
            'bool' => $builder->boolean($property->name, required: $required),
            'array' => $builder->json($property->name, required: $required),
            'datetime' => $builder->raw(DateTimeValidator::class, [$property->name], ['required' => $required]),
            // Case-sensitive: enum-backed values must round-trip exactly through BackedEnum::from().
            'enum' => $builder->enum($property->name, self::enumCaseValues($property), required: $required)->caseSensitive(),
        };
    }

    private static function applyNumberType(ValidatorSpec $spec, RequestDtoProperty $property): ValidatorSpec
    {
        $type = $property->kind === 'float' ? 'float' : 'integer';
        return $spec->type($type)->castTo($type);
    }

    /**
     * @return array<int, string|int>
     */
    private static function enumCaseValues(RequestDtoProperty $property): array
    {
        $enumClass = $property->enumClass;
        if ($enumClass === null) {
            throw new InvalidArgumentException('Property "' . $property->name . '" has kind "enum" but no enumClass was recorded.');
        }
        $values = [];
        foreach ($enumClass::cases() as $case) {
            $values[] = $case->value;
        }
        return $values;
    }

    private static function withMessage(ValidatorSpec $spec, ?string $message): ValidatorSpec
    {
        if ($message !== null) {
            $spec->error($message);
        }
        return $spec;
    }

    /**
     * @template T of object
     * @param class-string<T> $attributeClass
     * @return ?T
     */
    private static function firstAttribute(ReflectionParameter $parameter, string $attributeClass): ?object
    {
        $attributes = $parameter->getAttributes($attributeClass);
        if ($attributes === []) {
            return null;
        }
        return $attributes[0]->newInstance();
    }
}
