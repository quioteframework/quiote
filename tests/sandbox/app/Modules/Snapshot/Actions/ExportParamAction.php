<?php
namespace Sandbox\Modules\Snapshot\Actions;

use Quiote\Action\Action;
use Quiote\Request\WebRequest;

/**
 * Simple (no-validator) action that exports a value via setParameter() from
 * inside execute() and self-syncs it into Context, to prove ActionExecutor
 * re-fetches the request before rendering the view (see
 * ActionExecutor::doExecute()'s post-execute re-fetch). WebRequest is
 * immutable, so setParameter() only replaces the action's own local copy;
 * without the self-sync + re-fetch round trip this value would never reach
 * the view.
 */
class ExportParamAction extends Action
{
    #[\Override]
    public function isSimple(): bool { return true; }

    public function execute(WebRequest $rd)
    {
        $rd = $rd->setParameter('exported', 'from-action');
        $this->getContext()->setRequest($rd);
        return 'Success';
    }
}
