<?php

declare(strict_types=1);

namespace Quiote\Renderer\Twig;

use Twig\Error\LoaderError;
use Twig\Loader\LoaderInterface;
use Twig\Source;

/**
 * Twig loader that treats the template "name" Twig is given as a literal,
 * already-resolved filesystem path. Template resolution (directory
 * conventions, locale fallback, extension) is entirely the TemplateLayer's
 * job — {@see TwigRenderer} always calls Twig with
 * `$layer->getResourceStreamIdentifier()` as the name, so this loader never
 * needs a base directory or its own lookup rules.
 */
final class TemplateLayerLoader implements LoaderInterface
{
    /**
     * Reads the template at `$name` and returns it as a Twig source.
     *
     * `$name` is used verbatim as a filesystem path. Throws a `LoaderError`
     * when the file cannot be read, since Twig has no other way to signal a
     * missing template from here.
     *
     * @throws LoaderError
     */
    #[\Override]
    public function getSourceContext(string $name): Source
    {
        $contents = @file_get_contents($name);
        if ($contents === false) {
            throw new LoaderError(sprintf('Template "%s" could not be read.', $name));
        }

        return new Source($contents, $name, $name);
    }

    /**
     * Returns the cache key for a template.
     *
     * The name is already a fully resolved absolute path, so it is unique on
     * its own and is returned unchanged.
     */
    #[\Override]
    public function getCacheKey(string $name): string
    {
        return $name;
    }

    /**
     * Reports whether the compiled template cached at `$time` is still current.
     *
     * Compares the template file's modification time against `$time`. A file
     * whose mtime cannot be read is treated as stale, so Twig recompiles it
     * rather than serving a cache entry that may no longer match.
     */
    #[\Override]
    public function isFresh(string $name, int $time): bool
    {
        $mtime = @filemtime($name);

        return $mtime !== false && $mtime <= $time;
    }

    /**
     * Reports whether the template path exists and is readable by this process.
     *
     * A file that exists but is not readable counts as missing, because
     * {@see self::getSourceContext()} could not load it either.
     */
    #[\Override]
    public function exists(string $name): bool
    {
        return is_readable($name);
    }
}
