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
    #[\Override]
    public function getSourceContext(string $name): Source
    {
        $contents = @file_get_contents($name);
        if ($contents === false) {
            throw new LoaderError(sprintf('Template "%s" could not be read.', $name));
        }

        return new Source($contents, $name, $name);
    }

    #[\Override]
    public function getCacheKey(string $name): string
    {
        return $name;
    }

    #[\Override]
    public function isFresh(string $name, int $time): bool
    {
        $mtime = @filemtime($name);

        return $mtime !== false && $mtime <= $time;
    }

    #[\Override]
    public function exists(string $name): bool
    {
        return is_readable($name);
    }
}
