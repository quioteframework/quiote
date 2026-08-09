<?php

declare(strict_types=1);

namespace Quiote\Docs\Ir;

/** Everything one page needs about one class, interface, trait or enum. */
final class ClassDoc
{
    /**
     * @param 'class'|'interface'|'trait'|'enum' $kind
     * @param list<TypeRef> $interfaces
     * @param list<TypeRef> $traits
     * @param list<ConstantDoc> $constants
     * @param list<EnumCaseDoc> $cases
     * @param list<PropertyDoc> $properties
     * @param list<MethodDoc> $methods Declared here, trait-composed included.
     * @param list<InheritedMember> $inheritedMethods
     * @param list<string> $implementedBy Fully-qualified names, filled in once every class is known.
     */
    public function __construct(
        public readonly string $fqcn,
        public readonly string $shortName,
        public readonly string $namespace,
        public readonly string $kind,
        public readonly DocBlock $doc,
        public readonly string $sourcePath,
        public readonly bool $abstract = false,
        public readonly bool $final = false,
        public readonly bool $readonly = false,
        public readonly ?TypeRef $parent = null,
        public readonly array $interfaces = [],
        public readonly array $traits = [],
        public readonly array $constants = [],
        public readonly array $cases = [],
        public readonly array $properties = [],
        public readonly ?MethodDoc $constructor = null,
        public readonly array $methods = [],
        public readonly array $inheritedMethods = [],
        public readonly array $implementedBy = [],
        public readonly ?string $backingType = null,
    ) {
    }

    /** The declaration line, as it would be written in source. */
    public function declaration(): string
    {
        $parts = [];
        if ($this->abstract && $this->kind === 'class') {
            $parts[] = 'abstract';
        }
        // An enum is always final; saying so adds nothing.
        if ($this->final && $this->kind !== 'enum') {
            $parts[] = 'final';
        }
        if ($this->readonly) {
            $parts[] = 'readonly';
        }
        $parts[] = $this->kind;
        $parts[] = $this->shortName;

        $line = implode(' ', $parts);

        if ($this->kind === 'enum' && $this->backingType !== null) {
            $line .= ': ' . $this->backingType;
        }
        if ($this->parent !== null) {
            $line .= ' extends ' . $this->parent->display;
        }
        if ($this->interfaces !== []) {
            $keyword = $this->kind === 'interface' ? ' extends ' : ' implements ';
            $line .= $keyword . implode(', ', array_map(
                static fn(TypeRef $t): string => $t->display,
                $this->interfaces,
            ));
        }

        return $line;
    }

    /** @return list<string> The namespace split into segments below the framework root. */
    public function namespaceSegments(): array
    {
        $relative = $this->namespace === 'Quiote'
            ? ''
            : (str_starts_with($this->namespace, 'Quiote\\')
                ? substr($this->namespace, 7)
                : $this->namespace);

        return $relative === '' ? [] : explode('\\', $relative);
    }

    /** @param list<string> $implementedBy */
    public function withImplementedBy(array $implementedBy): self
    {
        return new self(
            $this->fqcn,
            $this->shortName,
            $this->namespace,
            $this->kind,
            $this->doc,
            $this->sourcePath,
            $this->abstract,
            $this->final,
            $this->readonly,
            $this->parent,
            $this->interfaces,
            $this->traits,
            $this->constants,
            $this->cases,
            $this->properties,
            $this->constructor,
            $this->methods,
            $this->inheritedMethods,
            $implementedBy,
            $this->backingType,
        );
    }
}
