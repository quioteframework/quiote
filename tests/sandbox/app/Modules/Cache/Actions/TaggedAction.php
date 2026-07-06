<?php
namespace Sandbox\Modules\Cache\Actions;

use Quiote\Action\Action;
use Quiote\Action\SlotCacheableTrait;
use Quiote\Request\WebRequest;

/**
 * Exercises SlotCacheableTrait::slotCacheTags() by deriving a cache tag from a
 * slot parameter, so bumping that tag's namespace version invalidates only the
 * slot cache entries carrying it.
 */
class TaggedAction extends Action
{
    use SlotCacheableTrait;

    public static int $execCount = 0;

    #[\Override]
    public function isSimple(){ return true; }
    #[\Override]
    public function getDefaultViewName(){ return 'Success'; }

    public function slotCacheTags(array $parameters = []): array
    {
        $group = $parameters['group'] ?? 'default';
        return ['group:' . $group];
    }

    public function execute(WebRequest $rd)
    {
        self::$execCount++;
        return 'Success';
    }
}
