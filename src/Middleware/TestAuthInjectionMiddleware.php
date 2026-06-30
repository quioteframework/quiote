<?php
namespace Agavi\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Agavi\Controller\AgaviController;

/**
 * Test-only auth injection middleware. Looks for __auth attribute and sets user->setAuthenticated before security.
 * Not for production use.
 */
class TestAuthInjectionMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly AgaviController $controller) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $desired = $request->getAttribute('__auth');
        if($desired !== null) {
            try {
                $user = $this->controller->getContext()->getUser();
                if(method_exists($user,'setAuthenticated')) { $user->setAuthenticated((bool)$desired); }
            } catch(\Throwable) {}
        }
        return $handler->handle($request);
    }
}

?>
