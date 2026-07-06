<?php
namespace Sandbox\Modules\Cache\Actions;

use Quiote\Action\Action;
use Quiote\Action\SlotCacheableTrait;
use Quiote\Request\WebRequest;

/**
 * Exercises SlotCacheableTrait::slotCacheTtlSeconds() via a short, test-configurable TTL.
 */
class TtlAction extends Action
{
    use SlotCacheableTrait;

    public static int $execCount = 0;
    public static ?int $ttlSeconds = null;

    #[\Override]
    public function isSimple(){ return true; }
    #[\Override]
    public function getDefaultViewName(){ return 'Success'; }

    public function slotCacheTtlSeconds(): ?int
    {
        return self::$ttlSeconds;
    }

    public function execute(WebRequest $rd)
    {
        self::$execCount++;
        return 'Success';
    }
}
