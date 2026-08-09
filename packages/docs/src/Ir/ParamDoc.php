<?php

declare(strict_types=1);

namespace Quiote\Docs\Ir;

/** One parameter of a method, with the type actually rendered and its prose. */
final class ParamDoc
{
    public function __construct(
        public readonly string $name,
        public readonly TypeRef $type,
        public readonly bool $byReference = false,
        public readonly bool $variadic = false,
        public readonly bool $promoted = false,
        public readonly ?string $default = null,
        public readonly string $description = '',
    ) {
    }

    /** The parameter as it appears in a signature, defaults and all. */
    public function signature(): string
    {
        $rendered = $this->type->display !== '' ? $this->type->display . ' ' : '';
        $rendered .= ($this->byReference ? '&' : '') . ($this->variadic ? '...' : '') . '$' . $this->name;

        return $this->default !== null ? $rendered . ' = ' . $this->default : $rendered;
    }
}
