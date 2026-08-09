<?php

declare(strict_types=1);

namespace Quiote\Docs\Ir;

/** One method, as documented on the page of the class that declares it. */
final class MethodDoc
{
    /**
     * @param list<ParamDoc> $parameters
     * @param 'public'|'protected' $visibility
     * @param string|null $fromTrait Fully-qualified trait this method was composed in from, if any.
     */
    public function __construct(
        public readonly string $name,
        public readonly array $parameters,
        public readonly TypeRef $returnType,
        public readonly DocBlock $doc,
        public readonly string $visibility = 'public',
        public readonly bool $static = false,
        public readonly bool $abstract = false,
        public readonly bool $final = false,
        public readonly ?string $fromTrait = null,
    ) {
    }

    /**
     * The full signature line.
     *
     * Modifiers are included because they are part of what a caller needs to know: a static
     * method is called differently, and an abstract one has to be implemented.
     */
    public function signature(): string
    {
        $prefix = '';
        if ($this->abstract) {
            $prefix .= 'abstract ';
        }
        if ($this->final) {
            $prefix .= 'final ';
        }
        $prefix .= $this->visibility . ' ';
        if ($this->static) {
            $prefix .= 'static ';
        }

        $parameters = implode(', ', array_map(
            static fn(ParamDoc $p): string => $p->signature(),
            $this->parameters,
        ));

        return $prefix . 'function ' . $this->name . '(' . $parameters . '): ' . $this->returnType->display;
    }

    /** The compact form used in an at-a-glance table, without modifiers. */
    public function shortSignature(): string
    {
        $parameters = implode(', ', array_map(
            static fn(ParamDoc $p): string => $p->signature(),
            $this->parameters,
        ));

        return $this->name . '(' . $parameters . '): ' . $this->returnType->display;
    }
}
