<?php

declare(strict_types=1);

namespace Quiote\Docs\Emitter;

/**
 * The small amount of Markdown and YAML escaping the emitters share.
 *
 * Docblock prose was written for a PHP comment, not for a table cell or a YAML scalar, so
 * anything crossing into one has to be made safe first: an unescaped pipe silently splits a
 * row, and a colon or a quote can make a frontmatter block unparsable and fail the site build.
 */
final class Markdown
{
    /**
     * A YAML scalar that survives whatever the prose contains.
     *
     * Always quoted rather than quoted-when-necessary: the rules for when a plain scalar is
     * safe are subtle enough that guessing wrong shows up as a broken build in another
     * repository.
     */
    public function yamlScalar(string $value): string
    {
        $escaped = str_replace(
            ['\\', '"', "\n", "\r", "\t"],
            ['\\\\', '\\"', ' ', '', ' '],
            $value,
        );

        return '"' . trim((string) preg_replace('/\s+/', ' ', $escaped)) . '"';
    }

    /** Collapses prose onto one line, for a description or a table cell. */
    public function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Prose made safe for a table cell.
     *
     * A pipe would end the cell, and a line break would end the row.
     */
    public function cell(string $text): string
    {
        return str_replace('|', '\\|', $this->oneLine($text));
    }

    /**
     * A Markdown table in the site's own style: terse separators, no padding, no alignment
     * colons.
     *
     * @param list<string> $headers
     * @param list<list<string>> $rows
     * @param bool $headerless Renders an empty header row, for a two-column fact list where
     *                         column titles would say nothing.
     */
    public function table(array $headers, array $rows, bool $headerless = false): string
    {
        if ($rows === []) {
            return '';
        }

        $columns = count($headers);
        $out = '| ' . implode(' | ', $headerless ? array_fill(0, $columns, '') : $headers) . " |\n";
        $out .= '|' . str_repeat('---|', $columns) . "\n";

        foreach ($rows as $row) {
            $cells = array_pad($row, $columns, '');
            $out .= '| ' . implode(' | ', array_slice($cells, 0, $columns)) . " |\n";
        }

        return $out;
    }
}
