<?php

namespace Quiote\Renderer\Phptal;

use PHPTAL;
use Quiote\Config\Config;
use Quiote\Exception\RenderException;
use Quiote\Renderer\Renderer;
use Quiote\Util\Toolkit;
use Quiote\View\TemplateLayer;

/**
 * Renders PHPTAL (`.tal`) templates through the standalone phptal/phptal
 * engine. Compiled template classes are cached under
 * `<core.cache_dir>/templates/phptal/`, mirroring the layout the other
 * on-disk template caches (e.g. the config cache) use.
 */
final class PhptalRenderer extends Renderer
{
    private const CACHE_SUBDIR = 'templates' . DIRECTORY_SEPARATOR . 'phptal';

    protected $defaultExtension = '.tal';

    private ?PHPTAL $engine = null;

    #[\Override]
    public function __sleep()
    {
        $keys = parent::__sleep();
        unset($keys[array_search('engine', $keys, true)]);
        return array_values($keys);
    }

    private function engine(): PHPTAL
    {
        if ($this->engine !== null) {
            return $this->engine;
        }

        $baseCacheDir = Config::getString('core.cache_dir');
        $cacheDir = rtrim($baseCacheDir, '/\\')
            . DIRECTORY_SEPARATOR . self::CACHE_SUBDIR . DIRECTORY_SEPARATOR;
        $baseCacheDirMode = fileperms($baseCacheDir);
        Toolkit::mkdir($cacheDir, $baseCacheDirMode !== false ? $baseCacheDirMode : 0775, true);

        $engine = new PHPTAL();
        $engine->setPhpCodeDestination($cacheDir);

        if ($this->hasParameter('encoding')) {
            $encoding = $this->getParameter('encoding');
            if (!is_scalar($encoding) && !$encoding instanceof \Stringable) {
                throw new RenderException("The 'encoding' parameter must be a string.");
            }
            $engine->setEncoding((string) $encoding);
        }

        return $this->engine = $engine;
    }

    #[\Override]
    public function render(TemplateLayer $layer, array &$attributes = [], array &$slots = [], array &$moreAssigns = [])
    {
        $engine = $this->engine();

        if ($this->extractVars) {
            foreach ($attributes as $name => $value) {
                $engine->set((string) $name, $value);
            }
        } else {
            $engine->set($this->varName, $attributes);
        }

        $engine->set($this->slotsVarName, $slots);

        foreach ($this->assigns as $variable => $getter) {
            $engine->set((string) $variable, $this->getContext()->$getter());
        }

        $extraAssigns = self::buildMoreAssigns($moreAssigns, $this->moreAssignNames);
        foreach ($extraAssigns as $variable => $value) {
            $engine->set((string) $variable, $value);
        }

        $template = $layer->getResourceStreamIdentifier();
        if ($template === null) {
            throw new RenderException('No template is set on the template layer.');
        }
        $engine->setTemplate($template);

        return $engine->execute();
    }

    #[\Override]
    public function getStarterTemplate(): string
    {
        $path = $this->extractVars ? 'title' : "{$this->varName}/title";
        return "<p tal:content=\"{$path} | default\">Untitled</p>\n";
    }

    #[\Override]
    public function reset(): void
    {
        $this->engine = null;
        parent::reset();
    }
}
