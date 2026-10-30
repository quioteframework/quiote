<?php

declare(strict_types=1);

namespace Quiote\DevOverlay\Http;

/**
 * Places a script tag just before a document's closing `</body>`.
 *
 * Only a full document gets one. A response without `</body>` is a fragment -- the HTML an htmx
 * or `fetch()` call swaps into a page that already carries the overlay -- and injecting into it
 * would load a second overlay into that page.
 */
final class HtmlScriptInjector
{
    /** The attribute every injected tag carries, so a document is never injected twice. */
    public const string MARKER = 'data-quiote-overlay';

    /**
     * @param array<string, string> $attributes Rendered as `data-*` attributes, escaped.
     * @return ?string The document with the tag inserted, or null when it is not a full
     *                 document or already carries one.
     */
    public function inject(string $html, string $src, array $attributes = []): ?string
    {
        $closingBody = strripos($html, '</body>');
        if ($closingBody === false || str_contains($html, self::MARKER)) {
            return null;
        }

        $tag = '<script src="' . htmlspecialchars($src, ENT_QUOTES) . '" ' . self::MARKER;
        foreach ($attributes as $name => $value) {
            $tag .= ' data-' . $name . '="' . htmlspecialchars($value, ENT_QUOTES) . '"';
        }
        $tag .= ' defer></script>';

        return substr($html, 0, $closingBody) . $tag . substr($html, $closingBody);
    }
}
