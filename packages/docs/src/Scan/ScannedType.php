<?php

declare(strict_types=1);

namespace Quiote\Docs\Scan;

/**
 * One class-like declaration, as read out of its source file by the tokenizer.
 *
 * This is what {@see SourceScanner} produces before any autoloading happens. The
 * fully-qualified name here is the one the file actually declares, not one derived
 * from the file's path, which is the distinction that makes it safe to reflect.
 */
final class ScannedType
{
    /**
     * @param 'class'|'interface'|'trait'|'enum' $kind
     * @param array<string, string> $imports Alias (lowercased) => fully-qualified name, from the
     *                                       file's top-level `use` statements. Needed to resolve
     *                                       the unqualified `{@see Foo}` docblock references that
     *                                       reflection alone cannot.
     */
    public function __construct(
        public readonly string $fqcn,
        public readonly string $shortName,
        public readonly string $namespace,
        public readonly string $kind,
        public readonly string $absolutePath,
        public readonly string $baseDir,
        public readonly array $imports,
    ) {
    }

    /**
     * Returns the path of this file relative to the PSR-4 base directory it was found under.
     *
     * Source links are built from this rather than from ReflectionClass::getFileName(), which
     * resolves symlinks: the monorepo's vendor entries are symlinks into packages/, so the same
     * class reports a different absolute path in a checkout than in a published install.
     */
    public function relativePath(): string
    {
        $base = rtrim($this->baseDir, '/') . '/';

        return str_starts_with($this->absolutePath, $base)
            ? substr($this->absolutePath, strlen($base))
            : $this->absolutePath;
    }

    /** Resolves an alias used in this file to its fully-qualified name, or null when unimported. */
    public function resolveImport(string $alias): ?string
    {
        return $this->imports[strtolower($alias)] ?? null;
    }
}
