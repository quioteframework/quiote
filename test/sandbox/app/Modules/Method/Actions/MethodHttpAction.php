<?php
namespace Sandbox\Modules\Method\Actions;

use Agavi\Action\AgaviAction;
use Agavi\Request\AgaviWebRequest ;

class MethodHttpAction extends AgaviAction
{
    public static string $last = '';
    #[\Override]
    public function isSimple(){ return false; }
    public function validatePost(AgaviWebRequest $rd){
    $present = $rd->hasParameter('fail');
    $val = $present ? $rd->getParameter('fail') : null;
    self::$last = 'validatePost:' . ($present ? (string)$val : 'missing');
    if(!$present) { return true; }
    return !((string)$val === '1');
    }
    #[\Override]
    public function validate(AgaviWebRequest $rd){ if(self::$last==='') self::$last = 'validate'; return true; } // ensure validatePost can overwrite last
    public function handlePostError(AgaviWebRequest $rd){ self::$last = 'handlePostError'; return 'PostError'; }
    #[\Override]
    public function handleError(AgaviWebRequest $rd){ self::$last = 'handleError'; return 'GenericError'; }
    public function executePost(AgaviWebRequest $rd){ self::$last = 'executePost'; return 'Post'; }
    public function execute(AgaviWebRequest $rd){ self::$last = 'execute'; return 'Generic'; }
    #[\Override]
    public function getDefaultViewName(){ return 'Generic'; }

    // Helper for tests
    public static function ensureReset(): void { self::$last = ''; }
}
