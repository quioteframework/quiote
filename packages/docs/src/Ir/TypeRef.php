<?php

declare(strict_types=1);

namespace Quiote\Docs\Ir;

/**
 * A type, kept as a tree rather than a string so each part can be linked independently.
 *
 * `list<Quiote\Routing\Route>|null` has to render with `Route` pointing at its own page
 * while `list` and `null` stay plain, which a flat string cannot express. Every node
 * carries a `display` form so a renderer that does not care about linking can ignore the
 * structure entirely.
 */
final class TypeRef
{
    public const KIND_NAMED = 'named';
    public const KIND_UNION = 'union';
    public const KIND_INTERSECTION = 'intersection';
    public const KIND_NULLABLE = 'nullable';
    public const KIND_GENERIC = 'generic';
    public const KIND_LITERAL = 'literal';

    /**
     * @param list<TypeRef> $args Members of a union or intersection, arguments of a generic,
     *                            or the single wrapped type of a nullable.
     */
    private function __construct(
        public readonly string $kind,
        public readonly string $display,
        public readonly ?string $fqcn = null,
        public readonly array $args = [],
    ) {
    }

    /** A class-like type, which may or may not be one the reference documents. */
    public static function named(string $fqcn): self
    {
        $short = str_contains($fqcn, '\\')
            ? substr($fqcn, (int) strrpos($fqcn, '\\') + 1)
            : $fqcn;

        return new self(self::KIND_NAMED, $short, ltrim($fqcn, '\\'));
    }

    /** A keyword, scalar or anything else with no class behind it to link to. */
    public static function literal(string $text): self
    {
        return new self(self::KIND_LITERAL, $text);
    }

    public static function nullable(self $inner): self
    {
        // `?null` and `?T|null` are noise; a type that already admits null stays as it is.
        if ($inner->kind === self::KIND_NULLABLE || $inner->display === 'null' || $inner->display === 'mixed') {
            return $inner;
        }

        return new self(self::KIND_NULLABLE, '?' . $inner->display, null, [$inner]);
    }

    /** @param list<TypeRef> $members */
    public static function union(array $members): self
    {
        return self::composite(self::KIND_UNION, $members, '|');
    }

    /** @param list<TypeRef> $members */
    public static function intersection(array $members): self
    {
        return self::composite(self::KIND_INTERSECTION, $members, '&');
    }

    /** @param list<TypeRef> $arguments */
    public static function generic(self $base, array $arguments): self
    {
        if ($arguments === []) {
            return $base;
        }

        $display = $base->display . '<'
            . implode(', ', array_map(static fn(self $a): string => $a->display, $arguments))
            . '>';

        return new self(self::KIND_GENERIC, $display, $base->fqcn, [$base, ...$arguments]);
    }

    /** @param list<TypeRef> $members */
    private static function composite(string $kind, array $members, string $glue): self
    {
        if ($members === []) {
            return self::literal('mixed');
        }
        if (count($members) === 1) {
            return $members[0];
        }

        $display = implode($glue, array_map(static fn(self $m): string => $m->display, $members));

        return new self($kind, $display, null, $members);
    }

    /**
     * Every named type anywhere in the tree, so a renderer can decide what to link
     * without walking the structure itself.
     *
     * @return list<string>
     */
    public function referencedClasses(): array
    {
        $names = $this->kind === self::KIND_NAMED && $this->fqcn !== null ? [$this->fqcn] : [];

        foreach ($this->args as $arg) {
            foreach ($arg->referencedClasses() as $nested) {
                $names[] = $nested;
            }
        }

        return array_values(array_unique($names));
    }

    public function __toString(): string
    {
        return $this->display;
    }
}
