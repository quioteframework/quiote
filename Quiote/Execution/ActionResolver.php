<?php
namespace Quiote\Execution;

use Quiote\Action\Action;
use Quiote\Exception\QuioteException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Resolves which execute* method to call and invokes action, returning raw view token.
 * Centralizes logic currently duplicated in SlotDispatcher and ExecutionContainer.
 */
class ActionResolver
{
    /**
     * Execute an action selecting execute<Method>() fallback to execute().
     * @param Action $action
     * @param string $requestMethod e.g. GET/POST canonicalized to ucfirst form?
     * @param ServerRequestInterface $request
     * @return mixed Raw view token returned by action (string|array|View::NONE).
     */
    public function execute(Action $action, string $requestMethod, ServerRequestInterface $request): mixed
    {
        // Try exact, then canonicalized (e.g. POST -> Post), then semantic mapping (GET -> Read, POST -> Write)
        $candidates = [];
        $candidates[] = 'execute' . $requestMethod; // raw (legacy tests pass uppercase GET/POST)
        $canonical = 'execute' . ucfirst(strtolower($requestMethod));
        if($canonical !== end($candidates)) { $candidates[] = $canonical; }
        
        // Semantic mapping driven by HttpMethodMapper so both call sites agree
        // (GET -> Read, POST/PUT -> Write, PATCH -> Update, DELETE -> Remove).
        $candidates[] = 'execute' . ucfirst(HttpMethodMapper::toActionMethod($requestMethod));
        
        foreach($candidates as $methodName) {
            if(is_callable([$action, $methodName])) {
                return $action->$methodName($request);
            }
        }
        if(is_callable([$action, 'execute'])) {
            return $action->{'execute'}($request);
        }
        if(is_callable($action->getDefaultViewName(...))) {
            $view = $action->getDefaultViewName();
            if($view !== null && $view !== '') { 
                return $view; 
            }
        }
        throw new QuioteException('ActionResolver: no executable method variants ('.implode(',', $candidates).' or execute()) and no non-empty getDefaultViewName() on action '.$action::class);
    }
}
