<?php

declare(strict_types=1);

namespace Quiote\Docs\Emitter;

use Quiote\Docs\Ir\ApiIndex;
use Quiote\Docs\Ir\ClassDoc;
use Quiote\Support\Compiler\EmittedArtifact;

/** Renders the reference's own landing page: every top-level namespace and what it holds. */
final class ApiIndexEmitter
{
    public function __construct(
        private readonly ApiIndex $index,
        private readonly Markdown $markdown = new Markdown(),
        private readonly ?TypeLinker $linker = null,
    ) {
    }

    /**
     * The handful of types that sit directly in the framework's root namespace.
     *
     * They have no namespace index of their own -- that page is this one -- so without this
     * section nothing would link to them.
     */
    private function rootTypes(): string
    {
        $classes = $this->index->inNamespace('Quiote');
        if ($classes === []) {
            return '';
        }

        $rows = array_map(
            function (ClassDoc $c): array {
                $href = $this->linker?->link($c->fqcn) ?? ('/api/' . $this->index->slugger()->forClass($c->fqcn) . '/');

                $summary = $this->linker?->prose($c->doc->summary) ?? $c->doc->summary;

                return [
                    '[`' . $c->shortName . '`](' . $href . ')',
                    $this->markdown->cell($summary),
                ];
            },
            $classes,
        );

        return "\n## At the root\n\n" . $this->markdown->table(['Type', 'Description'], $rows);
    }

    public function emit(): EmittedArtifact
    {
        $classes = count($this->index->classes());

        $body = "---\n"
            . 'title: ' . $this->markdown->yamlScalar('API reference') . "\n"
            . 'description: ' . $this->markdown->yamlScalar(sprintf(
                'Every class, interface, trait and enum the framework ships — %d types across %d namespaces.',
                $classes,
                count($this->index->topLevelNamespaces()),
            )) . "\n"
            . "---\n";

        $body .= "\nThis reference is generated from the source, so it describes the version you have"
            . " installed rather than a release note. Each namespace below lists its own types;"
            . " a type's page carries its methods, the types they take and return, and where each"
            . " one is declared.\n";

        $body .= "\nFor how the pieces fit together, start from the guides instead:"
            . " [the request lifecycle](/architecture/request-lifecycle/) explains the path a"
            . " request takes, and [actions and views](/architecture/actions-and-views/) covers"
            . " the two classes you write most.\n";

        $rows = [];
        foreach ($this->index->topLevelNamespaces() as $namespace) {
            $slug = $this->index->slugger()->forNamespace($namespace);
            $count = count($this->index->under($namespace));
            $rows[] = [
                '[`' . $namespace . '`](/api/' . ($slug === '' ? '' : $slug . '/') . ')',
                (string) $count . ' ' . ($count === 1 ? 'type' : 'types'),
            ];
        }

        $body .= "\n## Namespaces\n\n" . $this->markdown->table(['Namespace', 'Contents'], $rows);
        $body .= $this->rootTypes();

        return EmittedArtifact::fromSource(rtrim($body) . "\n", 'index.md');
    }
}
