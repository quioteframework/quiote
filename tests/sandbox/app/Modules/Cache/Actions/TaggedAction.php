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

    // Deliberately NOT isSimple(): isSimple() means "skip execute*() entirely,
    // render getDefaultViewName() directly" (Agavi heritage, commit f166330f4).
    // This fixture exercises slot caching around a real execute() call, so it
    // must go through the normal (non-simple) path.
    #[\Override]
    public function getDefaultViewName(){ return 'Success'; }

    /**
     * @param array<string, mixed> $parameters
     * @return list<string>
     */
    public function slotCacheTags(array $parameters = []): array
    {
        $group = $parameters['group'] ?? 'default';
        return ['group:' . $group];
    }

    public function execute(WebRequest $rd): string
    {
        self::$execCount++;
        return 'Success';
    }
}
