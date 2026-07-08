<?php
namespace Sandbox\Modules\Snapshot\Actions;

use Quiote\Action\Action;
use Quiote\Request\WebRequest;

/**
 * Always-fails validation and explicitly overrides the response status via
 * the same setHttpStatusCode() convention DispatchMiddleware already honors
 * on the success path. Regression fixture proving ValidationMiddleware
 * respects an explicit override instead of hardcoding 400 unconditionally.
 */
class StatusOverrideFallbackAction extends Action
{
    #[\Override]
    public function isSimple(): bool { return false; }

    public function validate(WebRequest $rd): bool
    {
        return false;
    }

    public function handleReadError(WebRequest $rd): string
    {
        $context = $this->getContext();
        if ($context === null) {
            throw new \RuntimeException('StatusOverrideFallbackAction requires an initialized Context.');
        }

        $context->getController()->getGlobalResponse()->setHttpStatusCode(409);

        return 'Success';
    }
}
