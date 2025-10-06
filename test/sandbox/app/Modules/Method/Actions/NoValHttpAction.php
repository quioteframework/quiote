<?php
namespace Sandbox\Modules\Method\Actions;

use Agavi\Action\AgaviAction;
use Agavi\Request\AgaviWebRequest ;

class NoValHttpAction extends AgaviAction
{
    public static string $last = '';
    // Helper for tests to clear static tracking state
    public static function ensureReset(): void { self::$last = ''; }
    public function isSimple(){ return false; }
    public function validatePost(AgaviWebRequest $rd){
        $present = $rd->hasParameter('fail');
        $val = $present ? $rd->getParameter('fail') : null;
        self::$last = 'validatePost:' . ($present ? (string)$val : 'missing');
        // Should always return true if parameter list was stripped (present=false)
        // If present (unexpected under strict mode with zero validators), return false to highlight leak.
        return !$present; 
    }
    public function validate(AgaviWebRequest $rd){ if(self::$last==='') self::$last = 'validate'; return true; }
    public function handlePostError(AgaviWebRequest $rd){ self::$last = 'handlePostError'; return 'PostError'; }
    public function handleError(AgaviWebRequest $rd){ self::$last = 'handleError'; return 'GenericError'; }
    public function executePost(AgaviWebRequest $rd){ self::$last = 'executePost'; return 'Post'; }
    public function execute(AgaviWebRequest $rd){ self::$last = 'execute'; return 'Generic'; }
    public function getDefaultViewName(){ return 'Generic'; }
}
