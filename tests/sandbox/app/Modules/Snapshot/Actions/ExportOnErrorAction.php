<?php
namespace Sandbox\Modules\Snapshot\Actions;

use Quiote\Action\Action;
use Quiote\Request\WebRequest;

/**
 * Always-fails action that exports a value via setParameter() from inside
 * handleReadError() and self-syncs it into Context, to prove
 * ValidationMiddleware re-fetches the request before creating the error view
 * (see ValidationMiddleware's post-handle*Error() re-fetch). WebRequest is
 * immutable, so setParameter() only replaces the action's own local copy;
 * without the self-sync + re-fetch round trip this value would never reach
 * the error view.
 */
class ExportOnErrorAction extends Action
{
    #[\Override]
    public function isSimple(): bool { return false; }

    public function validate(WebRequest $rd): bool
    {
        return false;
    }

    public function handleReadError(WebRequest $rd)
    {
        $rd = $rd->setParameter('error_export', 'exported-on-failure');
        $this->getContext()->setRequest($rd);
        return 'Error';
    }
}
