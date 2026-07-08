<?php
namespace Sandbox\Modules\Snapshot\Actions;

use Quiote\Action\Action;
use Quiote\Request\WebRequest;

/**
 * Regression fixture: headers are just as attacker-controlled as query/body
 * parameters. An action with no header validators must see NO headers at all
 * in execute*() -- not Content-Type, not Authorization, not an arbitrary
 * custom header -- even though PSR-7's getHeaderLine() itself has no way to
 * refuse the call (see ValidationManager::execute()'s unconditional
 * pruneExtendedSources() call).
 */
class HeaderSnapshotAction extends Action
{
    /** @var array<string,string> */
    public static array $seenHeaders = [];

    public function execute(WebRequest $rd): string
    {
        self::$seenHeaders = [
            'content-type' => $rd->getHeaderLine('Content-Type'),
            'authorization' => $rd->getHeaderLine('Authorization'),
            'x-my-special-header' => $rd->getHeaderLine('X-My-Special-Header'),
        ];
        return 'Success';
    }
}
