<?php
namespace Agavi\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Agavi\Controller\AgaviController;
use Agavi\Http\PsrResponseAdapter;
use Agavi\Controller\AgaviExecutionContainer;
use Agavi\Exception\AgaviException;

/**
 * Placeholder security middleware: currently defers to legacy dispatch path.
 * Future: Inspect request attributes for module/action and run security logic prior to execution.
 */
class SecurityMiddleware implements MiddlewareInterface
{
    public function __construct(private AgaviController $controller) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $container = $request->getAttribute('_agavi_execution_container');
        if(!$container instanceof AgaviExecutionContainer) {
            // Without container yet, just continue
            return $handler->handle($request);
        }
        $action = $container->getActionInstance();
        if(!$action) {
            // Action instance created lazily inside execute(); perform minimal secure flag probe by instantiating if needed later.
            return $handler->handle($request);
        }
        if(!$action->isSecure()) {
            return $handler->handle($request);
        }
        $user = $this->controller->getContext()->getUser();
        if(!$user->isAuthenticated()) {
            // Replace container with login forward
            $login = $container->createSystemActionForwardContainer('login');
            $login->setSecurityForwarded(true);
            $request = $request->withAttribute('_agavi_execution_container', $login);
            return $handler->handle($request);
        }
        $cred = $action->getCredentials();
        if($cred !== null && !$user->hasCredentials($cred)) {
            $secure = $container->createSystemActionForwardContainer('secure');
            $request = $request->withAttribute('_agavi_execution_container', $secure);
        }
        return $handler->handle($request);
    }
}
