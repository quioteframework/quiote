<?php
namespace Sandbox\Modules\Cache\Views;

use Quiote\Execution\ActionInitContext;
use Quiote\Execution\ViewInitContext;
use Quiote\Request\WebRequest;
use Quiote\View\View;

/** A view that exists but fails while initializing, standing in for a view with a real bug. */
class CacheBrokenInitView extends View
{
    #[\Override]
    public function initialize(ActionInitContext|ViewInitContext $context)
    {
        throw new \RuntimeException('view initialize exploded');
    }

    public function execute(WebRequest $rd): string
    {
        return 'BROKEN_INIT_RENDERED';
    }
}
