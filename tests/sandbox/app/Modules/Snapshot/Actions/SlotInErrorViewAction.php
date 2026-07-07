<?php
namespace Sandbox\Modules\Snapshot\Actions;

use Quiote\Action\Action;
use Quiote\Request\WebRequest;

/**
 * Always-fails action whose error view renders a slot — regression fixture
 * for ValidationMiddleware's failure path, which renders the error view
 * before SlotMiddleware ever runs (SlotMiddleware normally runs AFTER
 * ValidationMiddleware — see MiddlewareAttributeOrderingTest), so
 * renderSlot() used to throw "SlotStack missing from request".
 */
class SlotInErrorViewAction extends Action
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
