<?php
namespace Sandbox\Modules\Snapshot\Actions;

use Quiote\Action\Action;
use Quiote\Request\WebRequest;

/**
 * isSimple() action used to prove end-to-end that execute() is never invoked
 * at all for a simple action (Agavi heritage, commit f166330f4: "skip
 * execute*() entirely, render getDefaultViewName() directly" -- not "run
 * execute*() with restricted access"). If execute() ran, $seenParams would be
 * populated; it must stay empty for the whole request.
 */
class ParamSnapshotAction extends Action
{
    /** @var array<string,mixed> */
    public static array $seenParams = [];

    #[\Override]
    public function isSimple(): bool { return true; }

    #[\Override]
    public function getDefaultViewName(): string { return 'Success'; }

    public function execute(WebRequest $rd)
    {
        self::$seenParams = [
            'id' => $rd->getParameter('id', null),
            'q'  => $rd->getParameter('q', null),
        ];
        return 'Success';
    }
}
