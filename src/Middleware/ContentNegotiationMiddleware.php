<?php
namespace Agavi\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Agavi\Controller\AgaviController;
use Middlewares\ContentType; // external negotiation middleware

/**
 * Minimal wrapper over middlewares/content-type.
 * Runs BEFORE routing so routing can overwrite the attribute.
 * If Accept absent, library falls back to its first default format; we still set that.
 * We disable nosniff header and save negotiated format name into 'output_type'.
 */
#[\Agavi\Middleware\Attribute\AgaviMiddleware(phase: 'pre_routing', priority: 50)]
class ContentNegotiationMiddleware implements MiddlewareInterface
{
    public function __construct(private AgaviController $controller) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // If already set (unlikely before routing) keep it.
        if($request->getAttribute('output_type')) {
            return $handler->handle($request);
        }
        // Use library defaults (comprehensive list). Disable nosniff header.
        $ct = (new ContentType())
            ->noSniff(false)
            ->attribute('output_type');
        return $ct->process($request, $handler);
    }
}
