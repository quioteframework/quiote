<?php
namespace Sandbox\Modules\Snapshot\Actions;

use Quiote\Action\Action;
use Quiote\Request\WebRequest ;

class SnapshotAction extends Action
{
    public static array $initialAttributes = [];
    public static array $postMutationAttributes = [];

    // Deliberately NOT isSimple(): isSimple() means "skip execute*() entirely,
    // render getDefaultViewName() directly" (Agavi heritage, commit f166330f4).
    // This fixture exercises attribute snapshotting around a real execute()
    // call, so it must go through the normal (non-simple) path.

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
