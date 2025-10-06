<?php
namespace Sandbox\Modules\Fingerprint\Views;

use Agavi\View\AgaviView;
use Agavi\Request\AgaviWebRequest ;

class FingerprintSecureSuccessView extends AgaviView
{
    public function execute(AgaviWebRequest $rd){ return '<div>SecureContentView</div>'; }
}
