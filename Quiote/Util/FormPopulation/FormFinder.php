<?php

declare(strict_types=1);

namespace Quiote\Util\FormPopulation;

use Quiote\Util\ParameterHolder;
use Quiote\Util\Toolkit;

/**
 * Decides which forms in the document get populated, and from what.
 *
 * There are two ways a caller says which form it means. A ParameterHolder
 * means "the form this request was submitted to", identified by comparing the
 * form's action against the request -- which is why the comparison below
 * accepts an absolute URL, a root-relative path, and a path relative to any
 * <base href>, since a template may have written any of the three. An array
 * keyed by form id means "these specific forms, each from its own data", and
 * skips the action comparison entirely.
 *
 * Forms named by id are returned in the order the caller listed them, not in
 * document order: error insertion happens as forms are visited, and a
 * re-populated form must be visited before the others.
 */
final readonly class FormFinder
{
    /**
     * @param \Closure(string, ?\DOMElement): array<int, \DOMElement> $queryElements
     */
    public function __construct(
        private \Closure $queryElements,
        private string $xmlnsPrefix,
    ) {}

    /**
     * The form elements to populate, in the order they should be visited.
     *
     * @param array<string, mixed> $cfg
     * @return array<int, \DOMElement>
     */
    public function find(mixed $populate, array $cfg): array
    {
        if (!is_array($populate)) {
            $xpath = Toolkit::expandVariables(
                self::asString($cfg['forms_xpath'] ?? ''),
                ['htmlnsPrefix' => $this->xmlnsPrefix]
            );

            return ($this->queryElements)($xpath, null);
        }

        $queries = [];
        foreach ($populate as $id => $data) {
            if (!is_string($id)) {
                continue;
            }

            $query = sprintf('@id="%s"', $id);
            if ($data === true) {
                // A re-populated form goes first, so its errors are inserted before any other's.
                array_unshift($queries, $query);
            } else {
                $queries[] = $query;
            }
        }

        // Queried one at a time and assembled by hand: a combined XPath expression returns
        // matches in document order, which would lose the ordering established above.
        $forms = [];
        foreach ($queries as $query) {
            $found = ($this->queryElements)(sprintf('//%sform[%s]', $this->xmlnsPrefix, $query), null);
            if ($found !== []) {
                $forms[] = $found[0];
            }
        }

        return $forms;
    }

    /**
     * The data a given form is populated from, or null when it is not this
     * request's form and should be left alone.
     *
     * $fallback supplies the data for a non-form container element, which the
     * forms_xpath configuration can select and which has no action to compare.
     */
    public function dataFor(
        \DOMElement $element,
        mixed $populate,
        string $requestUri,
        string $requestUrl,
        string $baseHref,
        ?ParameterHolder $fallback,
    ): ?ParameterHolder {
        if ($element->tagName !== 'form') {
            return $populate instanceof ParameterHolder ? $populate : $fallback;
        }

        if ($populate instanceof ParameterHolder) {
            return $this->actionMatches($element, $requestUri, $requestUrl, $baseHref) ? $populate : null;
        }

        if (is_array($populate)) {
            $id = $element->getAttribute('id');
            $data = $id !== '' ? ($populate[$id] ?? null) : null;

            return $data instanceof ParameterHolder ? $data : null;
        }

        return null;
    }

    /**
     * Whether the form posts back to the request being answered.
     *
     * A fragment is dropped first -- it never reaches the server. Then the
     * action is accepted as an absolute URL, as a root-relative path once
     * normalised, or as a path resolved against <base href>.
     */
    private function actionMatches(
        \DOMElement $form,
        string $requestUri,
        string $requestUrl,
        string $baseHref,
    ): bool {
        $action = (string) preg_replace('/#.*$/', '', trim($form->getAttribute('action')));
        $normalized = self::normalizePath($action);

        return $action === $requestUrl
            || (str_starts_with($action, '/') && $normalized === $requestUri)
            || $baseHref . $normalized === $requestUrl;
    }

    /** Collapses "." and ".." segments and duplicate slashes, as a browser would. */
    private static function normalizePath(string $path): string
    {
        return (string) preg_replace(
            ['#/\./#', '#/\.$#', '#[^\./]+/\.\.(/|\z)#', '#/{2,}#'],
            ['/', '/', '', '/'],
            $path
        );
    }

    private static function asString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';
    }
}
