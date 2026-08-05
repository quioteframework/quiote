<?php

declare(strict_types=1);

namespace Quiote\Rector\Tests\Rector\ContextUserToConstructorInjectionRector\Source;

use Quiote\Action\Action;

/**
 * An application's own base action: real applications put one or two of these between their actions
 * and the framework's, and a fixture extending Action directly does not exercise that.
 */
class AppBaseAction extends Action
{
}
