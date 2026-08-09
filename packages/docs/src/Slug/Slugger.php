<?php

declare(strict_types=1);

namespace Quiote\Docs\Slug;

/**
 * Turns a fully-qualified name into the URL path its page lives at.
 *
 * A directory per namespace segment below the framework root, then the class name in
 * kebab case: `Quiote\Routing\Compiler\TriadViewResolver` becomes
 * `routing/compiler/triad-view-resolver`. Nesting by namespace rather than flattening
 * is what keeps two classes that share a short name apart.
 */
final class Slugger
{
    /**
     * Names that a boundary-per-capital rule would chop in the wrong place.
     *
     * Only names where the rule visibly fails are listed. `Pdo`, `Otlp` and `Psr17` already
     * come out right because their authors wrote them in mixed case; `APCu` and `PHPUnit`
     * do not, and would become `ap-cu-` and `php-unit-`.
     *
     * @var list<string>
     */
    private const ACRONYMS = [
        'APCu',
        'PHPUnit',
        'OAuth',
        'PHP',
        'HTTPS',
        'HTTP',
        'JSON',
        'UUID',
        'XML',
        'DOM',
        'URI',
        'URL',
        'SQL',
        'PDO',
        'CSRF',
        'CORS',
        'JWT',
        'RBAC',
        'PSR',
        'SSE',
        'API',
        'DTO',
        'TTL',
    ];

    /** The page path for a class, relative to the reference root and without an extension. */
    public function forClass(string $fqcn): string
    {
        $segments = $this->namespaceSegments($fqcn);
        $shortName = $this->shortName($fqcn);

        $parts = array_map($this->kebab(...), $segments);
        $parts[] = $this->kebab($shortName);

        return implode('/', $parts);
    }

    /** The page path for a namespace's index, relative to the reference root. */
    public function forNamespace(string $namespace): string
    {
        $relative = $this->belowRoot($namespace);
        if ($relative === '') {
            return '';
        }

        return implode('/', array_map($this->kebab(...), explode('\\', $relative)));
    }

    /**
     * Strips the framework's own root namespace, which the reference does not repeat in
     * every path: `Quiote\Execution` addresses `execution/`, and `Quiote` itself addresses
     * the reference root.
     */
    private function belowRoot(string $namespace): string
    {
        if ($namespace === 'Quiote') {
            return '';
        }

        return str_starts_with($namespace, 'Quiote\\') ? substr($namespace, 7) : $namespace;
    }

    /**
     * Converts one identifier to kebab case.
     *
     * Acronyms are folded to ordinary capitalised words first, so the boundary rule below
     * never has to reason about a run of capitals that is really one word.
     */
    public function kebab(string $identifier): string
    {
        foreach (self::ACRONYMS as $acronym) {
            $identifier = str_replace($acronym, ucfirst(strtolower($acronym)), $identifier);
        }

        // A capital after a lowercase letter or a digit starts a word.
        $identifier = preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', $identifier) ?? $identifier;
        // The last capital of a run belongs to the word that follows it.
        $identifier = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1-$2', $identifier) ?? $identifier;

        return strtolower($identifier);
    }

    /** @return list<string> */
    private function namespaceSegments(string $fqcn): array
    {
        $namespace = str_contains($fqcn, '\\')
            ? substr($fqcn, 0, (int) strrpos($fqcn, '\\'))
            : '';

        $relative = $this->belowRoot($namespace);

        return $relative === '' ? [] : explode('\\', $relative);
    }

    private function shortName(string $fqcn): string
    {
        return str_contains($fqcn, '\\')
            ? substr($fqcn, (int) strrpos($fqcn, '\\') + 1)
            : $fqcn;
    }
}
