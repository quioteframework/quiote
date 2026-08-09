<?php

declare(strict_types=1);

namespace Quiote\Docs\Ir;

/**
 * The prose and tags of one docblock, already separated into the parts a page renders.
 *
 * Types here are still the raw strings the author wrote. Turning them into a {@see TypeRef}
 * needs the declaring file's imports, which only the reflector has, so that conversion
 * happens there rather than at parse time.
 */
final class DocBlock
{
    /**
     * @param array<string, string> $paramDescriptions Parameter name (no `$`) => description.
     * @param array<string, string> $paramTypes        Parameter name (no `$`) => raw type text.
     * @param list<array{type: string, description: string}> $throws
     * @param list<string> $see Raw `@see` targets, in source order.
     */
    public function __construct(
        public readonly string $summary = '',
        public readonly string $description = '',
        public readonly array $paramDescriptions = [],
        public readonly array $paramTypes = [],
        public readonly ?string $returnType = null,
        public readonly string $returnDescription = '',
        public readonly array $throws = [],
        public readonly ?string $deprecated = null,
        public readonly bool $internal = false,
        public readonly ?string $since = null,
        public readonly array $see = [],
        public readonly bool $inheritsDoc = false,
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }

    public function isEmpty(): bool
    {
        return $this->summary === '' && $this->description === '';
    }

    /**
     * Returns a copy carrying $parent's prose wherever this block left it out.
     *
     * Used for `{@inheritDoc}`, and for the common case of an override with tags but no
     * summary: the ancestor's description is the right one, and the override's own tags
     * still win because they describe this signature.
     */
    public function inheritFrom(self $parent): self
    {
        return new self(
            summary: $this->summary !== '' ? $this->summary : $parent->summary,
            description: $this->description !== '' ? $this->description : $parent->description,
            paramDescriptions: $this->paramDescriptions + $parent->paramDescriptions,
            paramTypes: $this->paramTypes + $parent->paramTypes,
            returnType: $this->returnType ?? $parent->returnType,
            returnDescription: $this->returnDescription !== '' ? $this->returnDescription : $parent->returnDescription,
            throws: $this->throws !== [] ? $this->throws : $parent->throws,
            deprecated: $this->deprecated ?? $parent->deprecated,
            internal: $this->internal || $parent->internal,
            since: $this->since ?? $parent->since,
            see: $this->see !== [] ? $this->see : $parent->see,
            inheritsDoc: false,
        );
    }
}
