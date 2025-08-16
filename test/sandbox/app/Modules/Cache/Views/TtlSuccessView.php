<?php

namespace Sandbox\Modules\Cache\Views;

use Agavi\Request\AgaviRequestDataHolder;
use Agavi\View\AgaviView;

class TtlSuccessView extends AgaviView {
    public function execute(AgaviRequestDataHolder $rd) {
        return 'TTL_OK';
    }
}
