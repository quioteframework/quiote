<?php
namespace Sandbox\Modules\Fingerprint\Views;

use Quiote\View\View;
use Quiote\Request\WebRequest ;

class FingerprintSecureSuccessView extends View
{
    public function execute(WebRequest $rd){ return '<div>SecureContentView</div>'; }
}
