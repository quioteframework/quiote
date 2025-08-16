<?php
namespace Agavi\Execution;

use Agavi\Action\AgaviAction;
use Agavi\Request\AgaviRequestDataHolder;
use Agavi\Exception\AgaviException;

/**
 * Resolves which execute* method to call and invokes action, returning raw view token.
 * Centralizes logic currently duplicated in SlotDispatcher and AgaviExecutionContainer.
 */
class ActionResolver
{
    /**
     * Execute an action selecting execute<Method>() fallback to execute().
     * @param AgaviAction $action
     * @param string $requestMethod e.g. GET/POST canonicalized to ucfirst form?
     * @param AgaviRequestDataHolder $rd
     * @return mixed Raw view token returned by action (string|array|AgaviView::NONE).
     */
    public function execute(AgaviAction $action, string $requestMethod, AgaviRequestDataHolder $rd): mixed
    {
        // Try exact, then canonicalized (e.g. POST -> Post)
        $candidates = [];
        $candidates[] = 'execute' . $requestMethod; // raw (legacy tests pass uppercase GET/POST)
        $canonical = 'execute' . ucfirst(strtolower($requestMethod));
        if($canonical !== end($candidates)) { $candidates[] = $canonical; }
        foreach($candidates as $methodName) {
            if(is_callable([$action, $methodName])) {
                return $action->$methodName($rd);
            }
        }
        if(is_callable([$action, 'execute'])) {
            return $action->{'execute'}($rd);
        }
        if(is_callable([$action, 'getDefaultViewName'])) {
            $view = $action->getDefaultViewName();
            if($view !== null && $view !== '') { return $view; }
        }
        throw new AgaviException('ActionResolver: no executable method variants ('.implode(',', $candidates).' or execute()) and no non-empty getDefaultViewName() on action '.get_class($action));
    }
}
