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

    public function isRequired(): bool
    {
        return !$this->nullable && !$this->hasDefault;
    }
}
