<?php
namespace Agavi\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Simple stack-based middleware dispatcher (LIFO) for Phase 1.
 */
class MiddlewareDispatcher implements RequestHandlerInterface
{
    /** @var MiddlewareInterface[] */
    private array $stack = [];

    public function __construct(private readonly RequestHandlerInterface $finalHandler)
    {
    }

    public function add(MiddlewareInterface $mw): void { $this->stack[] = $mw; }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $handler = array_reduce(
            array_reverse($this->stack),
            fn(RequestHandlerInterface $next, MiddlewareInterface $mw) => new readonly class($mw,$next) implements RequestHandlerInterface {
                public function __construct(private MiddlewareInterface $mw, private RequestHandlerInterface $next) {}
                public function handle(ServerRequestInterface $request): ResponseInterface { return $this->mw->process($request, $this->next); }
            },
            $this->finalHandler
        );
        return $handler->handle($request);
    }
}
