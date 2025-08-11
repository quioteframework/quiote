<?php
namespace Agavi\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Agavi\Controller\AgaviController;
use Agavi\Http\PsrResponseAdapter;
use Agavi\Http\PsrServerRequestAdapter;
use Agavi\Request\AgaviWebRequest;
use Agavi\Request\AgaviRequest;

/**
 * DispatchMiddleware replaces the legacy global filter chain + dispatch filter.
 * It creates an execution container from the legacy request and invokes container->execute().
 * Action-level filters (security/execution/action filters) still run inside container->execute()
 * until they are migrated to PSR middlewares.
 */
class DispatchMiddleware implements MiddlewareInterface
{
    public function __construct(private AgaviController $controller) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Acquire legacy request for module/action resolution
        $legacyReq = $request instanceof PsrServerRequestAdapter ? $request->getLegacyRequest() : null;
    if(!$legacyReq instanceof AgaviRequest) {
            // Fallback: run full legacy dispatch (should rarely occur)
            $resp = $this->controller->dispatch();
            return new PsrResponseAdapter($resp);
        }

        // Reuse container from routing step if present
        $container = $request->getAttribute('_agavi_execution_container');
        if(!$container) {
            // Build execution container (module/action defaults applied in helper)
            $container = $this->controller->createExecutionContainerFromRequest($legacyReq);
        }

        // Execute container (runs action-level legacy filter chain internally)
        $response = $container->execute();
        $container->setResponse($response); // mirror dispatch filter behavior

        return new PsrResponseAdapter($response);
    }
}
