<?php

declare(strict_types=1);

namespace Quiote\Docs\Ir;

use Quiote\Docs\Slug\Slugger;

/**
 * Every documented class, plus the lookups the emitters need across them.
 *
 * Cross-linking and the "implemented by" back-index both need to see the whole corpus,
 * so they are resolved once here rather than rediscovered per page.
 */
final class ApiIndex
{
    /** @var array<string, ClassDoc> */
    private readonly array $byFqcn;

    /** @var array<string, list<ClassDoc>> */
    private readonly array $byNamespace;

    /**
     * @param list<ClassDoc> $classes
     */
    public function __construct(
        array $classes,
        private readonly Slugger $slugger = new Slugger(),
    ) {
        $byFqcn = [];
        $byNamespace = [];

        foreach ($classes as $class) {
            $byFqcn[$class->fqcn] = $class;
            $byNamespace[$class->namespace][] = $class;
        }

        ksort($byFqcn, SORT_STRING);
        ksort($byNamespace, SORT_STRING);
        foreach ($byNamespace as $namespace => $members) {
            usort($members, static fn(ClassDoc $a, ClassDoc $b): int => strcmp($a->shortName, $b->shortName));
            $byNamespace[$namespace] = $members;
        }

        $this->byFqcn = $byFqcn;
        $this->byNamespace = $byNamespace;
    }

    /** @return list<ClassDoc> Ordered by fully-qualified name. */
    public function classes(): array
    {
        return array_values($this->byFqcn);
    }

    public function get(string $fqcn): ?ClassDoc
    {
        return $this->byFqcn[ltrim($fqcn, '\\')] ?? null;
    }

    public function has(string $fqcn): bool
    {
        return isset($this->byFqcn[ltrim($fqcn, '\\')]);
    }

    /** @return list<string> Namespaces that hold at least one class, ordered by name. */
    public function namespaces(): array
    {
        return array_keys($this->byNamespace);
    }

    /** @return list<ClassDoc> */
    public function inNamespace(string $namespace): array
    {
        return $this->byNamespace[$namespace] ?? [];
    }

    /**
     * Every namespace that needs a page, including the ones holding nothing but other
     * namespaces.
     *
     * `Quiote\Config\Util` declares no types of its own, only `Quiote\Config\Util\DOM`
     * below it. It still has to exist as a page, because the level above links to it on
     * the way down.
     *
     * @return list<string>
     */
    public function navigableNamespaces(): array
    {
        $all = [];

        foreach ($this->byNamespace as $namespace => $_) {
            $segments = explode('\\', $namespace);
            $path = '';
            foreach ($segments as $segment) {
                $path = $path === '' ? $segment : $path . '\\' . $segment;
                $all[$path] = true;
            }
        }

        $names = array_keys($all);
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * The namespaces the reference lists in its navigation: one level below the framework
     * root, which is the granularity a sidebar can carry without putting every class on
     * every page.
     *
     * @return list<string>
     */
    public function topLevelNamespaces(): array
    {
        $tops = [];

        foreach ($this->byNamespace as $namespace => $_) {
            $relative = str_starts_with($namespace, 'Quiote\\') ? substr($namespace, 7) : $namespace;
            $first = $relative === '' ? '' : explode('\\', $relative)[0];
            $tops[$first === '' ? 'Quiote' : 'Quiote\\' . $first] = true;
        }

        $names = array_keys($tops);
        sort($names, SORT_STRING);

        return $names;
    }

    /** @return list<ClassDoc> Every class at or below $namespace. */
    public function under(string $namespace): array
    {
        $prefix = $namespace . '\\';
        $found = [];

        foreach ($this->byNamespace as $candidate => $members) {
            if ($candidate === $namespace || str_starts_with($candidate, $prefix)) {
                foreach ($members as $member) {
                    $found[] = $member;
                }
            }
        }

        usort($found, static fn(ClassDoc $a, ClassDoc $b): int => strcmp($a->fqcn, $b->fqcn));

        return $found;
    }

    /** The page path for a documented class, or null when it is not part of the reference. */
    public function slugFor(string $fqcn): ?string
    {
        $fqcn = ltrim($fqcn, '\\');

        return isset($this->byFqcn[$fqcn]) ? $this->slugger->forClass($fqcn) : null;
    }

    public function slugger(): Slugger
    {
        return $this->slugger;
    }
}
