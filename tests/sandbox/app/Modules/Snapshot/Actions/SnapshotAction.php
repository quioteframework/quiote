<?php
namespace Sandbox\Modules\Snapshot\Actions;

use Quiote\Action\Action;
use Quiote\Request\WebRequest ;

class SnapshotAction extends Action
{
    public static array $initialAttributes = [];
    public static array $postMutationAttributes = [];

    #[\Override]
    public function isSimple(): bool { return true; }

    public function execute(WebRequest $rd)
    {
        $this->setAttribute('alpha', 'A');
        $this->setAttribute('beta', ['nested' => 1]);
        self::$initialAttributes = $this->getAttributes();
        $this->setAttribute('beta', ['nested' => 2]);
        $this->setAttribute('gamma', 'G');
        self::$postMutationAttributes = $this->getAttributes();
        return 'Success';
    }
}
