<?php
namespace Agavi\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Agavi\Controller\AgaviController;
use Agavi\Controller\AgaviExecutionContainer;
use Agavi\Http\PsrServerRequestAdapter;
use Agavi\Response\AgaviWebResponse; // assume exists
use Agavi\Http\PsrResponseAdapter;

/**
 * Wraps the existing Agavi global filter chain + dispatch logic inside PSR-15 middleware for Phase 1.
 */
class LegacyFilterChainMiddleware implements MiddlewareInterface
{
    public function __construct(private AgaviController $controller) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Build execution container via existing controller (simplified: assume route already resolved elsewhere later)
        $legacyReq = $request instanceof PsrServerRequestAdapter ? $request->getLegacyRequest() : null;
        $container = $this->controller->createExecutionContainerFromRequest($legacyReq); // TODO: implement helper method in controller if missing
        $filterChain = $this->controller->getFilterChain();
        $this->controller->loadFilters($filterChain, 'global');
        // Register dispatch filter last
        $filterChain->registerPre($this->controller->getFilter('dispatch'), 'agavi_dispatch_filter');
        $filterChain->execute($container, function($c) { /* action executed inside dispatch filter currently */ });
    $legacyResponse = $container->getResponse();
    return new PsrResponseAdapter($legacyResponse);
    }
}
