<?php
namespace Agavi\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Agavi\Controller\AgaviController;
use Agavi\Http\PsrResponseAdapter;

/**
 * Executes the Agavi controller dispatch once routing/module/action resolved.
 * For Phase 1 we still rely on legacy dispatch; later this will directly build and invoke action/view.
 */
class ActionExecutionMiddleware implements MiddlewareInterface
{
    public function __construct(private AgaviController $controller) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $resp = $this->controller->dispatch(); // relies on legacy global request state
        return new PsrResponseAdapter($resp);
    }
}
