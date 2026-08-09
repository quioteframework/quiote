<?php

declare(strict_types=1);

namespace Quiote\Docs\Emitter;

use Quiote\Docs\Ir\ApiIndex;
use Quiote\Docs\Ir\ClassDoc;
use Quiote\Docs\Ir\ConstantDoc;
use Quiote\Docs\Ir\EnumCaseDoc;
use Quiote\Docs\Ir\InheritedMember;
use Quiote\Docs\Ir\MethodDoc;
use Quiote\Docs\Ir\PropertyDoc;
use Quiote\Docs\Ir\TypeRef;
use Quiote\Support\Compiler\EmittedArtifact;

/**
 * Renders one class as a Starlight page.
 *
 * The output follows the site's own conventions: the H1 comes from the frontmatter title,
 * so the body starts at `##`; a lead paragraph precedes the first heading; internal links
 * are root-absolute and end in a slash. Signatures are inline code rather than fenced PHP,
 * which keeps several thousand syntax-highlighting passes out of the site build; the tables
 * beside them carry the linked types instead.
 */
final class ClassPageEmitter
{
    public function __construct(
        private readonly ApiIndex $index,
        private readonly TypeLinker $linker,
        private readonly Markdown $markdown = new Markdown(),
    ) {
    }

    public function emit(ClassDoc $class): EmittedArtifact
    {
        $slug = $this->index->slugger()->forClass($class->fqcn);

        $sections = [
            $this->frontmatter($class),
            $this->lead($class),
            $this->synopsis($class),
            $this->constants($class),
            $this->cases($class),
            $this->properties($class),
            $this->constructor($class),
            $this->methods($class),
            $this->inherited($class),
        ];

        $body = implode("\n", array_filter($sections, static fn(string $s): bool => $s !== ''));

        return EmittedArtifact::fromSource(rtrim($body) . "\n", $slug . '.md');
    }

    private function frontmatter(ClassDoc $class): string
    {
        $description = $class->doc->summary !== ''
            ? $class->doc->summary
            : sprintf('The %s %s in %s.', $class->shortName, $class->kind, $class->namespace);

        return "---\n"
            . 'title: ' . $this->markdown->yamlScalar($class->shortName) . "\n"
            . 'description: ' . $this->markdown->yamlScalar($this->markdown->oneLine($this->linker->plain($description))) . "\n"
            . "---\n";
    }

    private function lead(ClassDoc $class): string
    {
        $parts = [];

        if ($class->doc->summary !== '') {
            $parts[] = $this->linker->prose($class->doc->summary);
        } else {
            $parts[] = sprintf(
                'The `%s` %s. It carries no description of its own yet.',
                $class->shortName,
                $class->kind,
            );
        }

        if ($class->doc->description !== '') {
            $parts[] = $this->linker->prose($class->doc->description);
        }

        $lead = implode("\n\n", $parts) . "\n";

        if ($class->doc->deprecated !== null) {
            $note = $class->doc->deprecated !== ''
                ? ' ' . $this->linker->prose($class->doc->deprecated)
                : '';
            $lead .= "\n:::caution[Deprecated]\nThis " . $class->kind . ' is deprecated.' . $note . "\n:::\n";
        }

        return $lead;
    }

    private function synopsis(ClassDoc $class): string
    {
        $out = "\n## Synopsis\n\n`" . $class->declaration() . "`\n";

        $rows = [];

        if ($class->parent !== null) {
            $rows[] = ['Extends', $this->linker->render($class->parent)];
        }
        if ($class->interfaces !== []) {
            $rows[] = ['Implements', $this->renderList($class->interfaces)];
        }
        if ($class->traits !== []) {
            $rows[] = ['Uses', $this->renderList($class->traits)];
        }
        if ($class->implementedBy !== []) {
            $rows[] = ['Implemented by', $this->renderNames($class->implementedBy)];
        }
        if ($class->doc->since !== null) {
            $rows[] = ['Since', '`' . $class->doc->since . '`'];
        }
        $rows[] = ['Source', '`' . $class->sourcePath . '`'];

        $out .= "\n" . $this->markdown->table(['', ''], $rows, headerless: true);

        return $out;
    }

    /** @param list<TypeRef> $types */
    private function renderList(array $types): string
    {
        return implode(', ', array_map($this->linker->render(...), $types));
    }

    /** @param list<string> $names */
    private function renderNames(array $names): string
    {
        return implode(', ', array_map(
            fn(string $name): string => $this->linker->render(TypeRef::named($name)),
            $names,
        ));
    }

    private function constants(ClassDoc $class): string
    {
        if ($class->constants === []) {
            return '';
        }

        $rows = array_map(
            fn(ConstantDoc $c): array => [
                '`' . $c->name . '`',
                '`' . $c->value . '`',
                $this->markdown->cell($this->linker->prose($c->doc->summary)),
            ],
            $class->constants,
        );

        return "\n## Constants\n\n" . $this->markdown->table(['Constant', 'Value', 'Description'], $rows);
    }

    private function cases(ClassDoc $class): string
    {
        if ($class->cases === []) {
            return '';
        }

        $backed = $class->backingType !== null;

        $rows = array_map(
            fn(EnumCaseDoc $c): array => $backed
                ? ['`' . $c->name . '`', '`' . (string) $c->backingValue . '`', $this->markdown->cell($this->linker->prose($c->doc->summary))]
                : ['`' . $c->name . '`', $this->markdown->cell($this->linker->prose($c->doc->summary))],
            $class->cases,
        );

        $headers = $backed ? ['Case', 'Value', 'Description'] : ['Case', 'Description'];

        return "\n## Cases\n\n" . $this->markdown->table($headers, $rows);
    }

    private function properties(ClassDoc $class): string
    {
        if ($class->properties === []) {
            return '';
        }

        $rows = array_map(
            fn(PropertyDoc $p): array => [
                '`$' . $p->name . '`',
                $this->linker->render($p->type),
                $this->markdown->cell($this->linker->prose($this->propertyNote($p))),
            ],
            $class->properties,
        );

        return "\n## Properties\n\n" . $this->markdown->table(['Property', 'Type', 'Description'], $rows);
    }

    private function propertyNote(PropertyDoc $property): string
    {
        $modifiers = [];
        if ($property->static) {
            $modifiers[] = 'static';
        }
        if ($property->readonly) {
            $modifiers[] = 'readonly';
        }
        if ($property->visibility === 'protected') {
            $modifiers[] = 'protected';
        }

        $summary = $property->doc->summary;
        $prefix = $modifiers !== [] ? '_' . implode(', ', $modifiers) . '._' : '';

        return trim($prefix . ($prefix !== '' && $summary !== '' ? ' ' : '') . $summary);
    }

    private function constructor(ClassDoc $class): string
    {
        if ($class->constructor === null) {
            return '';
        }

        return "\n## Constructor\n" . $this->method($class->constructor, headingLevel: 3, anchorless: true);
    }

    private function methods(ClassDoc $class): string
    {
        if ($class->methods === []) {
            return '';
        }

        $rows = array_map(
            fn(MethodDoc $m): array => [
                '[`' . $m->shortSignature() . '`](#' . $this->anchor($m->name) . ')',
                $this->markdown->cell($this->linker->prose($m->doc->summary)),
            ],
            $class->methods,
        );

        $out = "\n## Methods\n\n" . $this->markdown->table(['Method', 'Description'], $rows);

        foreach ($class->methods as $method) {
            $out .= $this->method($method, headingLevel: 3);
        }

        return $out;
    }

    private function method(MethodDoc $method, int $headingLevel, bool $anchorless = false): string
    {
        $heading = str_repeat('#', $headingLevel);
        $out = "\n" . $heading . ' ' . $method->name . "()\n\n`" . $method->signature() . "`\n";

        if ($method->fromTrait !== null) {
            $out .= "\nComposed in from " . $this->linker->render(TypeRef::named($method->fromTrait)) . ".\n";
        }

        if ($method->doc->deprecated !== null) {
            $note = $method->doc->deprecated !== ''
                ? ' ' . $this->linker->prose($method->doc->deprecated)
                : '';
            $out .= "\n:::caution[Deprecated]\nThis method is deprecated." . $note . "\n:::\n";
        }

        if ($method->doc->summary !== '') {
            $out .= "\n" . $this->linker->prose($method->doc->summary) . "\n";
        }
        if ($method->doc->description !== '') {
            $out .= "\n" . $this->linker->prose($method->doc->description) . "\n";
        }

        if ($method->parameters !== []) {
            $rows = array_map(
                fn($p): array => [
                    '`$' . $p->name . '`',
                    $this->linker->render($p->type),
                    $this->markdown->cell($this->linker->prose($p->description)),
                ],
                $method->parameters,
            );
            $out .= "\n" . $this->markdown->table(['Parameter', 'Type', 'Description'], $rows);
        }

        if ($method->returnType->display !== 'void' && $method->returnType->display !== '') {
            $description = $method->doc->returnDescription;
            $out .= "\nReturns " . $this->linker->render($method->returnType)
                . ($description !== '' ? ' — ' . $this->markdown->oneLine($this->linker->prose($description)) : '') . "\n";
        }

        if ($method->doc->throws !== []) {
            $rows = [];
            foreach ($method->doc->throws as $throw) {
                $rows[] = [
                    '`' . $this->shortName($throw['type']) . '`',
                    $this->markdown->cell($this->linker->prose($throw['description'])),
                ];
            }
            $out .= "\n" . $this->markdown->table(['Throws', 'When'], $rows);
        }

        return $out;
    }

    private function inherited(ClassDoc $class): string
    {
        if ($class->inheritedMethods === []) {
            return '';
        }

        $rows = array_map(
            fn(InheritedMember $m): array => [
                '`' . $m->name . '()`',
                $this->linker->render(TypeRef::named($m->declaredIn)),
                $this->markdown->cell($this->linker->prose($m->summary)),
            ],
            $class->inheritedMethods,
        );

        return "\n## Inherited methods\n\n"
            . "These come from an ancestor and are documented where they are declared.\n\n"
            . $this->markdown->table(['Method', 'Declared in', 'Description'], $rows);
    }

    /** Matches how the site slugifies a `### name()` heading. */
    private function anchor(string $methodName): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '', $methodName));
    }

    private function shortName(string $fqcn): string
    {
        $fqcn = ltrim($fqcn, '\\');

        return str_contains($fqcn, '\\')
            ? substr($fqcn, (int) strrpos($fqcn, '\\') + 1)
            : $fqcn;
    }
}
