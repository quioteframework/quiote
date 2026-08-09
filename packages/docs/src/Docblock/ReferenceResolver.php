<?php

declare(strict_types=1);

namespace Quiote\Docs\Docblock;

use Quiote\Docs\Ir\DocBlock;
use Quiote\Docs\Scan\ScannedType;

/**
 * Rewrites the `{@see …}` references in docblock prose into a form an emitter can link.
 *
 * The framework writes these unqualified -- `{@see Context}`, `{@see reset()}` -- which only
 * the declaring file's imports can resolve, so it has to happen here rather than at render
 * time. Left alone they reach the page verbatim, braces and all, which is how a reference
 * ends up looking abandoned.
 *
 * Output is `{@link Fully\Qualified\Name::member}` when the target resolved and plain
 * backticked text when it did not, so the emitter never has to guess.
 */
final class ReferenceResolver
{
    /** Resolves every reference in a docblock's prose against the file that declared it. */
    public function resolve(DocBlock $doc, ScannedType $context): DocBlock
    {
        return new DocBlock(
            summary: $this->rewrite($doc->summary, $context),
            description: $this->rewrite($doc->description, $context),
            paramDescriptions: array_map(
                fn(string $text): string => $this->rewrite($text, $context),
                $doc->paramDescriptions,
            ),
            paramTypes: $doc->paramTypes,
            returnType: $doc->returnType,
            returnDescription: $this->rewrite($doc->returnDescription, $context),
            throws: array_map(
                fn(array $throw): array => [
                    'type' => $throw['type'],
                    'description' => $this->rewrite($throw['description'], $context),
                ],
                $doc->throws,
            ),
            deprecated: $doc->deprecated !== null ? $this->rewrite($doc->deprecated, $context) : null,
            internal: $doc->internal,
            since: $doc->since,
            see: $doc->see,
            inheritsDoc: $doc->inheritsDoc,
        );
    }

    private function rewrite(string $text, ScannedType $context): string
    {
        if ($text === '' || !str_contains($text, '{@')) {
            return $text;
        }

        return (string) preg_replace_callback(
            '/\{@(?:see|link)\s+([^}]+)\}/i',
            fn(array $matches): string => $this->reference(trim($matches[1]), $context),
            $text,
        );
    }

    private function reference(string $target, ScannedType $context): string
    {
        // A reference may carry its own label after the target; the label is prose, not a name.
        $parts = preg_split('/\s+/', $target, 2) ?: [$target];
        $symbol = $parts[0];

        if (preg_match('#^https?://#i', $symbol) === 1) {
            return isset($parts[1]) ? '[' . $parts[1] . '](' . $symbol . ')' : $symbol;
        }

        $resolved = $this->resolveSymbol($symbol, $context);

        return $resolved !== null ? '{@link ' . $resolved . '}' : '`' . $symbol . '`';
    }

    /**
     * Resolves one reference to `Fully\Qualified\Name` or `Fully\Qualified\Name::member`,
     * or null when there is nothing to point at.
     */
    private function resolveSymbol(string $symbol, ScannedType $context): ?string
    {
        $symbol = ltrim($symbol, '\\');

        // `$this->foo()` and `self::foo()` both mean a member of the class being documented.
        $symbol = (string) preg_replace('/^\$this->/', 'self::', $symbol);

        if (str_contains($symbol, '::')) {
            [$class, $member] = explode('::', $symbol, 2);

            $resolvedClass = $this->resolveClass($class, $context);

            return $resolvedClass !== null ? $resolvedClass . '::' . $member : null;
        }

        // A bare `method()` is a member of this class.
        if (str_ends_with($symbol, '()')) {
            return $context->fqcn . '::' . $symbol;
        }

        return $this->resolveClass($symbol, $context);
    }

    private function resolveClass(string $name, ScannedType $context): ?string
    {
        if ($name === '' ) {
            return null;
        }

        if ($name === 'self' || $name === 'static' || $name === '$this') {
            return $context->fqcn;
        }

        if (str_contains($name, '\\')) {
            return ltrim($name, '\\');
        }

        $imported = $context->resolveImport($name);
        if ($imported !== null) {
            return $imported;
        }

        // Lowercase names are keywords or prose, not types.
        if (!ctype_upper($name[0])) {
            return null;
        }

        return $context->namespace !== '' ? $context->namespace . '\\' . $name : $name;
    }
}
