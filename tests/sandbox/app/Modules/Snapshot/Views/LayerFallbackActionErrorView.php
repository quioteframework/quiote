<?php
namespace Sandbox\Modules\Snapshot\Views;

use Quiote\Renderer\Renderer;
use Quiote\Request\WebRequest;
use Quiote\View\TemplateLayer;
use Quiote\View\View;

/**
 * Returns null from execute(), like a classic setupHtml()/loadLayout() view,
 * relying on the caller to render its configured layer via renderLayers().
 */
class LayerFallbackActionErrorView extends View
{
    public function execute(WebRequest $rd)
    {
        $layer = new class extends TemplateLayer {
            public function execute(?Renderer $renderer = null, array &$attributes = [], array &$moreAssigns = []): string
            {
                return 'LAYER_RENDERED';
            }

            public function getResourceStreamIdentifier()
            {
                return null;
            }
        };
        $context = $this->getContext();
        if ($context === null) {
            throw new \RuntimeException('LayerFallbackActionErrorView requires an initialized Context.');
        }
        $layer->initialize($context, ['name' => 'content']);
        $this->appendLayer($layer);
    }
}
