<?php

declare(strict_types=1);

namespace Quiote\Docs\Emitter;

use Quiote\Docs\Ir\ApiIndex;
use Quiote\Docs\Ir\ClassDoc;
use Quiote\Support\Compiler\EmittedArtifact;

/**
 * Renders the index page for one namespace.
 *
 * These pages are what the site's navigation lists. Every class page is reached from the
 * table on one of them rather than from the sidebar, because a sidebar naming all several
 * hundred classes is rendered into every page of the site and would multiply its weight.
 */
final class NamespaceIndexEmitter
{
    public function __construct(
        private readonly ApiIndex $index,
        private readonly TypeLinker $linker,
        private readonly Markdown $markdown = new Markdown(),
    ) {
    }

    public function emit(string $namespace): EmittedArtifact
    {
        $slug = $this->index->slugger()->forNamespace($namespace);
        $target = ($slug === '' ? 'index' : $slug . '/index') . '.md';

        $own = $this->index->inNamespace($namespace);
        $nested = $this->nestedNamespaces($namespace);

        $body = $this->frontmatter($namespace, count($this->index->under($namespace)))
            . $this->lead($namespace, $own, $nested)
            . $this->kindSections($own)
            . $this->nestedSection($nested);

        return EmittedArtifact::fromSource(rtrim($body) . "\n", $target);
    }

    private function frontmatter(string $namespace, int $total): string
    {
        $short = $this->shortName($namespace);

        return "---\n"
            . 'title: ' . $this->markdown->yamlScalar($short) . "\n"
            . 'description: ' . $this->markdown->yamlScalar(sprintf(
                'The %s namespace — %d documented %s.',
                $namespace,
                $total,
                $total === 1 ? 'type' : 'types',
            )) . "\n"
            . "---\n";
    }

    /**
     * @param list<ClassDoc> $own
     * @param list<string> $nested
     */
    private function lead(string $namespace, array $own, array $nested): string
    {
        $sentence = sprintf('Everything under `%s`.', $namespace);

        if ($own === [] && $nested !== []) {
            $sentence .= ' This namespace holds no types of its own; the ones below do.';
        }

        return "\n" . $sentence . "\n";
    }

    /**
     * @param list<ClassDoc> $classes
     */
    private function kindSections(array $classes): string
    {
        $groups = ['class' => [], 'interface' => [], 'trait' => [], 'enum' => []];

        foreach ($classes as $class) {
            $groups[$class->kind][] = $class;
        }

        $titles = [
            'class' => 'Classes',
            'interface' => 'Interfaces',
            'trait' => 'Traits',
            'enum' => 'Enums',
        ];

        $out = '';

        foreach ($groups as $kind => $members) {
            if ($members === []) {
                continue;
            }

            $rows = array_map(
                fn(ClassDoc $c): array => [
                    '[`' . $c->shortName . '`](' . (string) $this->linker->link($c->fqcn) . ')',
                    $this->markdown->cell($this->linker->prose($c->doc->summary)),
                ],
                $members,
            );

            $out .= "\n## " . $titles[$kind] . "\n\n"
                . $this->markdown->table([ucfirst($kind), 'Description'], $rows);
        }

        return $out;
    }

    /**
     * @param list<string> $nested
     */
    private function nestedSection(array $nested): string
    {
        if ($nested === []) {
            return '';
        }

        $rows = [];
        foreach ($nested as $namespace) {
            $slug = $this->index->slugger()->forNamespace($namespace);
            $count = count($this->index->under($namespace));
            $rows[] = [
                '[`' . $this->shortName($namespace) . '`](/api/' . $slug . '/)',
                (string) $count . ' ' . ($count === 1 ? 'type' : 'types'),
            ];
        }

        return "\n## Nested namespaces\n\n" . $this->markdown->table(['Namespace', 'Contents'], $rows);
    }

    /**
     * Namespaces directly below this one, skipping levels that hold nothing themselves.
     *
     * @return list<string>
     */
    private function nestedNamespaces(string $namespace): array
    {
        $prefix = $namespace . '\\';
        $children = [];

        foreach ($this->index->namespaces() as $candidate) {
            if (!str_starts_with($candidate, $prefix)) {
                continue;
            }

            $remainder = substr($candidate, strlen($prefix));
            $child = $prefix . explode('\\', $remainder)[0];
            $children[$child] = true;
        }

        $names = array_keys($children);
        sort($names, SORT_STRING);

        return $names;
    }

    private function shortName(string $namespace): string
    {
        return str_contains($namespace, '\\')
            ? substr($namespace, (int) strrpos($namespace, '\\') + 1)
            : $namespace;
    }
}
