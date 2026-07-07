<?php
namespace Sandbox\Modules\Snapshot\Actions;

use Quiote\Action\Action;
use Quiote\Request\WebRequest;

/**
 * Always-fails action whose error view follows the loadLayout()/appendLayer()
 * convention (execute() implicitly returns null, content comes from a
 * configured layer) — regression fixture for ValidationMiddleware's HTML
 * error-view path, which used to return an empty body instead of falling
 * back to View::renderLayers() like ActionExecutor::renderView() does.
 */
class LayerFallbackAction extends Action
{
    #[\Override]
    public function isSimple(): bool { return false; }

    public function validate(WebRequest $rd): bool
    {
        return false;
    }

    public function handleError(WebRequest $rd)
    {
        return 'Error';
    }
}
