<?php
namespace Sandbox\Modules\Snapshot\Actions;

use Quiote\Action\Action;
use Quiote\Request\WebRequest ;

class SnapshotActionAction extends Action
{
    /** @var array<int|string, mixed> */
    public static array $initialAttributes = [];
    /** @var array<int|string, mixed> */
    public static array $postMutationAttributes = [];

    // Deliberately NOT isSimple(): isSimple() means "skip execute*() entirely,
    // render getDefaultViewName() directly" (Agavi heritage, commit f166330f4).

    public function execute(WebRequest $rd): string
    {
        // set attributes before snapshot
        $this->setAttribute('alpha', 'A');
        $this->setAttribute('beta', ['nested' => 1]);
        self::$initialAttributes = $this->getAttributes();
        // mutate after (simulating late mutation that should NOT appear in snapshot)
        $this->setAttribute('beta', ['nested' => 2]);
        $this->setAttribute('gamma', 'G');
        self::$postMutationAttributes = $this->getAttributes();
        return 'Success';
    }
}
