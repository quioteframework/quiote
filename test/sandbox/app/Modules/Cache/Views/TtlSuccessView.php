<?php

namespace Sandbox\Modules\Cache\Views;

use Agavi\Request\AgaviWebRequest ;
use Agavi\View\AgaviView;

class TtlSuccessView extends AgaviView {
    public function execute(AgaviWebRequest $rd) {
        return 'TTL_OK';
    }
}
