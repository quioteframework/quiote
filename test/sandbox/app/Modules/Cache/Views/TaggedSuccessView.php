<?php
namespace Sandbox\Modules\Cache\Views;

use Agavi\Request\AgaviRequestDataHolder;
use Agavi\View\AgaviView;

class TaggedSuccessView extends AgaviView {
    public function execute(AgaviRequestDataHolder $rd) {
        return 'TAG_OK';
    }
}