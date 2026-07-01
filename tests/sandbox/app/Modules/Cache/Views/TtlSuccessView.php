<?php

namespace Sandbox\Modules\Cache\Views;

use Quiote\Request\WebRequest ;
use Quiote\View\View;

class TtlSuccessView extends View {
    public function execute(WebRequest $rd) {
        return 'TTL_OK';
    }
}
