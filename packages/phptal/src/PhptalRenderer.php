<?php

namespace Quiote\Renderer\Phptal;

use PHPTAL;
use Quiote\Config\Config;
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

        $cacheDir = rtrim((string) Config::get('core.cache_dir'), '/\\')
            . DIRECTORY_SEPARATOR . self::CACHE_SUBDIR . DIRECTORY_SEPARATOR;
        Toolkit::mkdir($cacheDir, fileperms((string) Config::get('core.cache_dir')), true);

        $engine = new PHPTAL();
        $engine->setPhpCodeDestination($cacheDir);

        if ($this->hasParameter('encoding')) {
            $engine->setEncoding((string) $this->getParameter('encoding'));
        }

        return $this->engine = $engine;
    }

    #[\Override]
    public function render(TemplateLayer $layer, array &$attributes = [], array &$slots = [], array &$moreAssigns = [])
    {
        $engine = $this->engine();

        if ($this->extractVars) {
            foreach ($attributes as $name => $value) {
                $engine->set($name, $value);
            }
        } else {
            $engine->set($this->varName, $attributes);
        }

        $engine->set($this->slotsVarName, $slots);

        foreach ($this->assigns as $variable => $getter) {
            $engine->set($variable, $this->getContext()->$getter());
        }

        $extraAssigns = self::buildMoreAssigns($moreAssigns, $this->moreAssignNames);
        foreach ($extraAssigns as $variable => $value) {
            $engine->set($variable, $value);
        }

        $engine->setTemplate($layer->getResourceStreamIdentifier());

        return $engine->execute();
    }

    #[\Override]
    public function reset(): void
    {
        $this->engine = null;
        parent::reset();
    }
}
