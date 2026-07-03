<?php
namespace Sandbox\Modules\Snapshot\Actions;

use Quiote\Action\Action;
use Quiote\Request\WebRequest;

/**
 * Simple (no-validator) action used to prove end-to-end that a simple action
 * still receives both a promoted route param and a query param after
 * ValidationMiddleware's overlay is skipped for simple actions (perf A3).
 * The route param is promoted by ValidationMiddleware; the query param is
 * applied by ActionExecutor::buildRequestDataFromPsr.
 */
class ParamSnapshotAction extends Action
{
    /** @var array<string,mixed> */
    public static array $seenParams = [];

    #[\Override]
    public function isSimple(): bool { return true; }

    public function execute(WebRequest $rd)
    {
        self::$seenParams = [
            'id' => $rd->getParameter('id', null),
            'q'  => $rd->getParameter('q', null),
        ];
        return 'Success';
    }
}
