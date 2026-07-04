<?php

declare(strict_types=1);

namespace Quiote\Renderer\Twig;

use Quiote\Config\Config;
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

        $cacheDir = Config::get('core.cache_dir');
        $compileDir = rtrim((string) $cacheDir, '/\\') . DIRECTORY_SEPARATOR . self::CACHE_SUBDIR;
        Toolkit::mkdir($compileDir, fileperms((string) $cacheDir), true);

        return $this->environment = new Environment(new TemplateLayerLoader(), [
            'cache' => $compileDir,
            'auto_reload' => (bool) $this->getParameter('auto_reload', true),
            'strict_variables' => (bool) $this->getParameter('strict_variables', false),
            'autoescape' => $this->getParameter('autoescape', 'html'),
        ]);
    }

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

        foreach ($this->assigns as $name => $getter) {
            $vars[$name] = $this->getContext()->$getter();
        }

        foreach (self::buildMoreAssigns($moreAssigns, $this->moreAssignNames) as $name => $value) {
            $vars[$name] = $value;
        }

        return $this->environment()->render($layer->getResourceStreamIdentifier(), $vars);
    }

    #[\Override]
    public function reset(): void
    {
        $this->environment = null;
        parent::reset();
    }
}
