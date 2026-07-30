<?php
namespace Sandbox\Modules\ControllerTests\Actions;

use Quiote\Action\Action;

/**
 * Fixture used by ActionTestCaseTest to exercise the failure path of
 * ActionTestCase::runAction() when an action returns a non-string view name.
 */
class InvalidViewReturnAction extends Action
{
    public function executeRead(): mixed
    {
        return ['not', 'a', 'view', 'name'];
    }
}
?>
