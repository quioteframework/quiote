<?php
namespace Sandbox\Modules\Snapshot\Actions;

use Quiote\Action\Action;
use Quiote\Request\WebRequest;

/**
 * No-validator action that exports a value via setParameter() from inside
 * execute() and self-syncs it into Context, to prove ActionExecutor
 * re-fetches the request before rendering the view (see
 * ActionExecutor::doExecute()'s post-execute re-fetch). WebRequest is
 * immutable, so setParameter() only replaces the action's own local copy;
 * without the self-sync + re-fetch round trip this value would never reach
 * the view.
 *
 * Deliberately NOT isSimple(): isSimple() means "skip execute*() entirely,
 * render getDefaultViewName() directly" (Agavi heritage, commit f166330f4) --
 * execute() would never run for a simple action, defeating the point of this
 * test.
 */
class ExportParamAction extends Action
{
    public function execute(WebRequest $rd): string
    {
        $context = $this->getContext();
        if ($context === null) {
            throw new \RuntimeException('ExportParamAction requires an initialized Context.');
        }

        $rd = $rd->setParameter('exported', 'from-action');
        $context->setRequest($rd);
        return 'Success';
    }
}
