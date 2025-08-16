<?php
namespace Sandbox\Modules\Snapshot\Actions;

use Agavi\Action\AgaviAction;
use Agavi\Request\AgaviRequestDataHolder;

class SnapshotAction extends AgaviAction
{
    public static array $initialAttributes = [];
    public static array $postMutationAttributes = [];

    public function isSimple(): bool { return true; }

    public function execute(AgaviRequestDataHolder $rd)
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
