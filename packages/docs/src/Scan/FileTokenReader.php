<?php

declare(strict_types=1);

namespace Quiote\Docs\Scan;

/**
 * Reads a PHP file's namespace, first class-like declaration and `use` imports
 * straight from its tokens, without executing or autoloading anything.
 *
 * The generator cannot ask the autoloader what a file declares. Composer's PSR-4
 * map allows one base directory under several prefixes, so a path can imply a
 * class name that does not exist; asking `class_exists()` about that name makes
 * Composer include the file a second time, and the resulting "Cannot redeclare
 * class" is a fatal that no catch block can intercept. Reading the declaration
 * first, and reflecting only when it matches what the path implied, is what keeps
 * the scan safe.
 */
final class FileTokenReader
{
    /**
     * Returns what the file declares, or null when it declares no class-like at all.
     *
     * Every top-level declaration is reported, not just the first. PSR-4 addresses one
     * type per file, but a file is free to declare more alongside it -- `Quiote\DI\Container`
     * ships its two PSR-11 exceptions that way, and they are defined the moment the file
     * loads, so they are as documentable as the type that named it. Which of them the path
     * points at is the scanner's business, not this reader's.
     *
     * @return array{namespace: string, imports: array<string, string>, types: list<array{name: string, kind: 'class'|'interface'|'trait'|'enum'}>}|null
     */
    public function read(string $path): ?array
    {
        $source = @file_get_contents($path);
        if ($source === false) {
            return null;
        }

        // A file with no class-like keyword at all cannot declare one; skip the tokenizer.
        if (!preg_match('/\b(class|interface|trait|enum)\b/i', $source)) {
            return null;
        }

        $tokens = @token_get_all($source);
        $namespace = '';
        $imports = [];
        $types = [];
        $depth = 0;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_string($token)) {
                if ($token === '{') {
                    $depth++;
                } elseif ($token === '}') {
                    $depth--;
                }
                continue;
            }

            switch ($token[0]) {
                case T_NAMESPACE:
                    if ($depth === 0) {
                        $namespace = $this->readName($tokens, $i + 1);
                    }
                    break;

                case T_USE:
                    // Only a top-level `use` is an import: inside a class body it pulls in a
                    // trait, and after a closure signature it captures variables.
                    if ($depth === 0 && !$this->isClosureCapture($tokens, $i + 1)) {
                        foreach ($this->readImports($tokens, $i + 1) as $alias => $fqcn) {
                            $imports[$alias] = $fqcn;
                        }
                    }
                    break;

                case T_CLASS:
                case T_INTERFACE:
                case T_TRAIT:
                case T_ENUM:
                    // Only a declaration at the top level of the file is addressable. Anything
                    // deeper is inside a class body or a function, where these keywords only
                    // appear as `::class` or an anonymous `new class`.
                    if ($depth === 0 && $this->isDeclaration($tokens, $i)) {
                        $name = $this->readName($tokens, $i + 1);
                        if ($name !== '') {
                            $types[] = ['name' => $name, 'kind' => $this->kindOf($token[0])];
                        }
                    }
                    break;
            }
        }

        if ($types === []) {
            return null;
        }

        return ['namespace' => $namespace, 'imports' => $imports, 'types' => $types];
    }

    /** @return 'class'|'interface'|'trait'|'enum' */
    private function kindOf(int $tokenType): string
    {
        return match ($tokenType) {
            T_INTERFACE => 'interface',
            T_TRAIT => 'trait',
            T_ENUM => 'enum',
            default => 'class',
        };
    }

    /**
     * Distinguishes a real declaration from the other places these keywords appear:
     * `Foo::class`, an anonymous `new class`, and `enum` used as a plain identifier.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function isDeclaration(array $tokens, int $index): bool
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $previous = $tokens[$i];
            if (is_array($previous) && $previous[0] === T_WHITESPACE) {
                continue;
            }
            if (is_array($previous) && in_array($previous[0], [T_DOUBLE_COLON, T_NEW, T_OBJECT_OPERATOR, T_FUNCTION], true)) {
                return false;
            }
            break;
        }

        // A declaration is always followed by its name.
        for ($i = $index + 1; $i < count($tokens); $i++) {
            $next = $tokens[$i];
            if (is_array($next) && $next[0] === T_WHITESPACE) {
                continue;
            }
            return is_array($next) && $next[0] === T_STRING;
        }

        return false;
    }

    /**
     * Reports whether this `use` is a closure's variable capture rather than an import,
     * which it is when the next meaningful token opens a parameter list.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function isClosureCapture(array $tokens, int $index): bool
    {
        for ($i = $index; $i < count($tokens); $i++) {
            $token = $tokens[$i];
            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }
            return $token === '(';
        }

        return false;
    }

    /**
     * Reads one `use` statement, covering the plain, aliased and braced group forms.
     *
     * `function` and `const` imports are skipped: they never name a type, so they cannot
     * be the target of a docblock reference.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array<string, string> Lowercased alias => fully-qualified name.
     */
    private function readImports(array $tokens, int $index): array
    {
        $imports = [];
        $prefix = '';
        $current = '';
        $alias = null;
        $inGroup = false;
        $count = count($tokens);

        for ($i = $index; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_string($token)) {
                if ($token === ';') {
                    $this->collect($imports, $prefix, $current, $alias);
                    break;
                }
                if ($token === ',') {
                    $this->collect($imports, $prefix, $current, $alias);
                    $current = '';
                    $alias = null;
                    continue;
                }
                if ($token === '{') {
                    $prefix = $current;
                    $current = '';
                    $inGroup = true;
                    continue;
                }
                if ($token === '}') {
                    $this->collect($imports, $prefix, $current, $alias);
                    $current = '';
                    $alias = null;
                    $inGroup = false;
                    $prefix = '';
                    continue;
                }
                continue;
            }

            switch ($token[0]) {
                case T_WHITESPACE:
                case T_COMMENT:
                case T_DOC_COMMENT:
                    break;
                case T_FUNCTION:
                case T_CONST:
                    // A symbol import, not a type import.
                    if (!$inGroup) {
                        return $imports;
                    }
                    break;
                case T_AS:
                    $alias = '';
                    break;
                case T_STRING:
                case T_NAME_QUALIFIED:
                case T_NAME_FULLY_QUALIFIED:
                case T_NS_SEPARATOR:
                    if ($alias !== null) {
                        $alias = $token[1];
                    } else {
                        $current .= $token[1];
                    }
                    break;
            }
        }

        return $imports;
    }

    /**
     * @param array<string, string> $imports
     */
    private function collect(array &$imports, string $prefix, string $name, ?string $alias): void
    {
        $name = trim($name, '\\');
        if ($name === '') {
            return;
        }

        $fqcn = $prefix !== '' ? trim($prefix, '\\') . '\\' . $name : $name;
        $key = $alias !== null && $alias !== ''
            ? $alias
            : (str_contains($name, '\\') ? substr($name, (int) strrpos($name, '\\') + 1) : $name);

        $imports[strtolower($key)] = $fqcn;
    }

    /**
     * Reads a (possibly qualified) name starting at $index, stopping at the first token
     * that cannot be part of one.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function readName(array $tokens, int $index): string
    {
        $name = '';
        $count = count($tokens);

        for ($i = $index; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && $token[0] === T_WHITESPACE) {
                if ($name !== '') {
                    break;
                }
                continue;
            }

            if (!is_array($token)) {
                break;
            }

            if (in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR], true)) {
                $name .= $token[1];
                continue;
            }

            break;
        }

        return trim($name, '\\');
    }
}
