<?php

declare(strict_types=1);

namespace Quiote\Rector\Tests\Rector\ContextServiceToConstructorInjectionRector\Source;

use Quiote\Action\Action;

/**
 * An application's own base action. Real applications put one or two of these between their actions
 * and the framework's, so a fixture whose class extends Action directly does not exercise the
 * hierarchy the rules actually meet.
 */
class AppBaseAction extends Action
{
}
