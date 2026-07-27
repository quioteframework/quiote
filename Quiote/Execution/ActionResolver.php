<?php
namespace Quiote\Execution;

use Quiote\Action\Action;
use Quiote\Exception\QuioteException;
use Quiote\Request\RequestDtoMapper;
use Quiote\Request\RequestDtoRegistry;
use Quiote\Request\WebRequest;
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
        $candidates = self::candidateMethodNames($requestMethod);

        foreach($candidates as $methodName) {
            if(is_callable([$action, $methodName])) {
                return $this->invoke($action, $methodName, $request);
            }
        }
        if(is_callable([$action, 'execute'])) {
            return $this->invoke($action, 'execute', $request);
        }
        if(is_callable($action->getDefaultViewName(...))) {
            $view = $action->getDefaultViewName();
            if($view !== null && $view !== '') {
                return $view;
            }
        }
        throw new QuioteException('ActionResolver: no executable method variants ('.implode(',', $candidates).' or execute()) and no non-empty getDefaultViewName() on action '.$action::class);
    }

    /**
     * Invokes the resolved action method, appending a constructed #[MapRequest]
     * DTO as a second positional argument when that method declares one --
     * see Quiote\Request\RequestDtoRegistry. Validation (which whitelists the
     * DTO's request parameters) has always already run by the time this is
     * reached, since ValidationMiddleware precedes DispatchMiddleware.
     */
    private function invoke(Action $action, string $methodName, ServerRequestInterface $request): mixed
    {
        $dtoClass = RequestDtoRegistry::dtoClassForMethod($action::class, $methodName);
        if ($dtoClass !== null && $request instanceof WebRequest) {
            return $action->$methodName($request, RequestDtoMapper::map($request, $dtoClass));
        }
        return $action->$methodName($request);
    }

    /**
     * Try exact, then canonicalized (e.g. POST -> Post), then semantic mapping (GET -> Read, POST -> Write).
     * Semantic mapping is driven by HttpMethodMapper (configurable via the
     * routing.http_method_map setting) so every call site agrees.
     * Default: GET/HEAD/OPTIONS/TRACE -> Read, POST -> Write, PUT/PATCH -> Update, DELETE -> Remove.
     * @return array<int, string>
     */
    public static function candidateMethodNames(string $requestMethod): array
    {
        $candidates = [];
        $candidates[] = 'execute' . $requestMethod; // raw (legacy tests pass uppercase GET/POST)
        $canonical = 'execute' . ucfirst(strtolower($requestMethod));
        if($canonical !== end($candidates)) { $candidates[] = $canonical; }

        $candidates[] = 'execute' . ucfirst(HttpMethodMapper::toActionMethod($requestMethod));

        return $candidates;
    }

    /**
     * The first execute*() method name this action actually implements for
     * the given HTTP verb, or null if none match (mirrors the candidate
     * resolution execute() itself uses, without the execute()/default-view
     * fallbacks -- callers needing that fallback should use execute()).
     */
    public static function resolveMethodName(Action $action, string $requestMethod): ?string
    {
        foreach(self::candidateMethodNames($requestMethod) as $methodName) {
            if(is_callable([$action, $methodName])) {
                return $methodName;
            }
        }
        return null;
    }
}
