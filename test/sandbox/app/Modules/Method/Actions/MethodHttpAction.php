<?php
namespace Sandbox\Modules\Method\Actions;

use Agavi\Action\AgaviAction;
use Agavi\Request\AgaviRequestDataHolder;

class MethodHttpAction extends AgaviAction
{
    public static string $last = '';
    public function isSimple(){ return false; }
    public function validatePost(AgaviRequestDataHolder $rd){
    $present = $rd->hasParameter('fail');
    $val = $present ? $rd->getParameter('fail') : null;
    self::$last = 'validatePost:' . ($present ? (string)$val : 'missing');
    if(!$present) { return true; }
    return !((string)$val === '1');
    }
    public function validate(AgaviRequestDataHolder $rd){ if(self::$last==='') self::$last = 'validate'; return true; } // ensure validatePost can overwrite last
    public function handlePostError(AgaviRequestDataHolder $rd){ self::$last = 'handlePostError'; return 'PostError'; }
    public function handleError(AgaviRequestDataHolder $rd){ self::$last = 'handleError'; return 'GenericError'; }
    public function executePost(AgaviRequestDataHolder $rd){ self::$last = 'executePost'; return 'Post'; }
    public function execute(AgaviRequestDataHolder $rd){ self::$last = 'execute'; return 'Generic'; }
    public function getDefaultViewName(){ return 'Generic'; }

    // Helper for tests
    public static function ensureReset(): void { self::$last = ''; }
}
