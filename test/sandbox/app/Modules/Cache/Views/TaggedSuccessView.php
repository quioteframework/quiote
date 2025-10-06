<?php
namespace Sandbox\Modules\Cache\Views;

use Agavi\Request\AgaviWebRequest ;
use Agavi\View\AgaviView;

class TaggedSuccessView extends AgaviView {
    public function execute(AgaviWebRequest $rd) {
        return 'TAG_OK';
    }
}