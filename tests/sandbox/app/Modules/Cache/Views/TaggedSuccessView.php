<?php
namespace Sandbox\Modules\Cache\Views;

use Quiote\Request\WebRequest ;
use Quiote\View\View;

class TaggedSuccessView extends View {
    public function execute(WebRequest $rd) {
        return 'TAG_OK';
    }
}