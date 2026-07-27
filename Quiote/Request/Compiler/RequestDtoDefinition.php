<?php
namespace Quiote\Request\Compiler;

/**
 * Format-independent description of a #[MapRequest] DTO class: its
 * constructor-promoted properties, in declaration order. Produced once per
 * class by RequestDtoScanner::scan() and cached by RequestDtoRegistry.
 * @since      1.0.0
 */
final class RequestDtoDefinition
{
    /**
     * @param array<int, RequestDtoProperty> $properties
     */
    public function __construct(
        public readonly string $className,
        public readonly array $properties,
    ) {
    }
}
