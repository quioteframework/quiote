<?php

declare(strict_types=1);

namespace Quiote\Renderer\Twig;

use Quiote\Config\Config;
use Quiote\Exception\RenderException;
use Quiote\Renderer\IReusableRenderer;
use Quiote\Renderer\Renderer;
use Quiote\Util\Toolkit;
use Quiote\View\TemplateLayer;
use Twig\Environment;

/**
 * Renders Twig (`.twig`) templates via twig/twig. Compiled templates are
 * cached under `<core.cache_dir>/templates/twig/`.
 */
final class TwigRenderer extends Renderer implements IReusableRenderer
{
    private const string CACHE_SUBDIR = 'templates' . DIRECTORY_SEPARATOR . 'twig';

    protected $defaultExtension = '.twig';

    private ?Environment $environment = null;

    private function environment(): Environment
    {
        if ($this->environment !== null) {
            return $this->environment;
        }

        $cacheDir = Config::getString('core.cache_dir');
        $compileDir = rtrim($cacheDir, '/\\') . DIRECTORY_SEPARATOR . self::CACHE_SUBDIR;
        $cacheDirMode = fileperms($cacheDir);
        Toolkit::mkdir($compileDir, $cacheDirMode !== false ? $cacheDirMode : 0775, true);

        return $this->environment = new Environment(new TemplateLayerLoader(), [
            'cache' => $compileDir,
            'auto_reload' => (bool) $this->getParameter('auto_reload', true),
            'strict_variables' => (bool) $this->getParameter('strict_variables', false),
            'autoescape' => $this->getParameter('autoescape', 'html'),
        ]);
    }

    /**
     * Renders the layer's `.twig` template and returns the result.
     *
     * Builds the Twig variable set from the attributes (spread individually
     * when `extract_vars` is on, otherwise nested under the configured
     * template variable), the slots, the renderer's assigns and the filtered
     * `$moreAssigns`, then hands the layer's resolved template path straight
     * to Twig — {@see TemplateLayerLoader} treats it as a literal file path.
     * The Twig environment is built lazily on first use and reused afterwards.
     *
     * @throws RenderException if the layer carries no template.
     */
    #[\Override]
    public function render(TemplateLayer $layer, array &$attributes = [], array &$slots = [], array &$moreAssigns = [])
    {
        $vars = [];

        if ($this->extractVars) {
            foreach ($attributes as $name => $value) {
                $vars[$name] = $value;
            }
        } else {
            $vars[$this->varName] = $attributes;
        }

        $vars[$this->slotsVarName] = $slots;

        foreach ($this->assigns as $name => $resolve) {
            $vars[$name] = $resolve();
        }

        foreach (self::buildMoreAssigns($moreAssigns, $this->moreAssignNames) as $name => $value) {
            $vars[$name] = $value;
        }

        $template = $layer->getResourceStreamIdentifier();
        if ($template === null) {
            throw new RenderException('No template is set on the template layer.');
        }

        return $this->environment()->render($template, $vars);
    }

    /**
     * Returns the skeleton `.twig` template written for a newly scaffolded view.
     *
     * The printed expression follows the renderer's current variable
     * configuration: a bare `title` when `extract_vars` is on, otherwise
     * `title` under the configured template variable.
     */
    #[\Override]
    public function getStarterTemplate(): string
    {
        $expr = $this->extractVars ? 'title' : "{$this->varName}.title";
        return "<p>{{ {$expr}|default('Untitled')|e }}</p>\n";
    }

    /**
     * Returns the renderer to its post-construction state for reuse.
     *
     * Drops the cached Twig environment — so the next render rebuilds it
     * against the then-current cache directory and `auto_reload`,
     * `strict_variables` and `autoescape` parameters — and then lets the
     * parent clear the layer, variable names and assigns.
     */
    #[\Override]
    public function reset(): void
    {
        $this->environment = null;
        parent::reset();
    }
}
