<?php
namespace Sandbox\Modules\Cache\Actions;

use Agavi\Action\AgaviAction;
use Agavi\Renderer\AgaviRenderer;

class CacheSimpleAction extends AgaviAction
{
    public function execute(?AgaviRenderer $renderer = null)
    {
        return 'Success';
    }
}
?>