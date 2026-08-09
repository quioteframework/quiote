<?php
namespace Quiote\Request\Compiler;

/**
 * One constructor-promoted property of a #[MapRequest] DTO, as reflected by
 * RequestDtoScanner. Carries everything RequestDtoMapper needs to pull a
 * value back out of an already-validated WebRequest and cast it to the
 * property's declared PHP type.
 * @since      1.0.0
 */
final class RequestDtoProperty
{
    /**
     * @param 'string'|'int'|'float'|'bool'|'array'|'datetime'|'enum' $kind
     * @param ?class-string $enumClass Set only when $kind === 'enum'.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $kind,
        public readonly bool $nullable,
        public readonly bool $hasDefault,
        public readonly mixed $defaultValue,
        public readonly ?string $enumClass = null,
    ) {
    }

    /**
     * Reports whether the mapper has to find a value for this property.
     *
     * True only when the property is neither nullable nor has a constructor
     * default: in either of those cases the DTO can still be built without an
     * incoming value.
     */
    public function isRequired(): bool
    {
        return !$this->nullable && !$this->hasDefault;
    }
}
