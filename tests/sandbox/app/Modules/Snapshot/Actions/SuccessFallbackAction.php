<?php
namespace Sandbox\Modules\Snapshot\Actions;

use Quiote\Action\Action;
use Quiote\Request\WebRequest;

/**
 * Always-fails validation, but handleReadError() falls back to the literal
 * success view name -- e.g. an invalid ?lang= falling back to the default
 * locale's normal page instead of an error page. The view name is a
 * presentation choice only; the response is still a 400 unless
 * getGlobalResponse()->setHttpStatusCode() is called explicitly.
 */
class SuccessFallbackAction extends Action
{
    #[\Override]
    public function isSimple(): bool { return false; }

    public function validate(WebRequest $rd): bool
    {
        return false;
    }

    public function handleReadError(WebRequest $rd): string
    {
        return 'Success';
    }
}
