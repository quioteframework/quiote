<?php
namespace Sandbox\Modules\Snapshot\Actions;

use Quiote\Action\Action;
use Quiote\Request\WebRequest ;

class SnapshotActionAction extends Action
{
    public static array $initialAttributes = [];
    public static array $postMutationAttributes = [];

    #[\Override]
    public function isSimple(): bool { return true; }

    public function execute(WebRequest $rd)
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
