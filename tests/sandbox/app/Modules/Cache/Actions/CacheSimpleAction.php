<?php
namespace Sandbox\Modules\Cache\Actions;

use Quiote\Action\Action;
use Quiote\Renderer\Renderer;

class CacheSimpleAction extends Action
{
    public function execute(?Renderer $renderer = null): string
    {
        return 'Success';
    }
}
?>