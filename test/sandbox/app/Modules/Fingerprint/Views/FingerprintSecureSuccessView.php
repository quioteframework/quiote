<?php
namespace Sandbox\Modules\Fingerprint\Views;

use Agavi\View\AgaviView;
use Agavi\Request\AgaviRequestDataHolder;

class FingerprintSecureSuccessView extends AgaviView
{
    public function execute(AgaviRequestDataHolder $rd){ return '<div>SecureContentView</div>'; }
}
