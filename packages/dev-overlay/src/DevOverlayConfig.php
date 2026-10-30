<?php

declare(strict_types=1);

namespace Quiote\DevOverlay;

use Quiote\Config\Config;

/**
 * The `dev_overlay.*` settings as one immutable value, read once per request.
 *
 * {@see $active} folds `core.developer_exceptions` in: the overlay serves full exception detail
 * (messages, file paths, source excerpts) to anyone who can load a page, so it follows the exact
 * gate that decides whether a client sees Whoops rather than the safe error page, and
 * `dev_overlay.enabled` can only switch it off on top of that, never on without it.
 */
final readonly class DevOverlayConfig
{
    public function __construct(
        public bool $active,
        public string $path,
        public string $editorUrl,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            active: Config::getBool('core.developer_exceptions', false) && Config::getBool('dev_overlay.enabled', true),
            path: self::normalizePath(Config::getString('dev_overlay.path', '/_quiote/overlay')),
            editorUrl: Config::getString('dev_overlay.editor_url', ''),
        );
    }

    /** A leading slash and no trailing one, so `$path . '/x'` is always a well-formed URL path. */
    private static function normalizePath(string $path): string
    {
        $trimmed = trim($path, '/');

        return $trimmed === '' ? '/_quiote/overlay' : '/' . $trimmed;
    }
}
