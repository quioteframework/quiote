<?php
namespace Quiote\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Http\PsrResponseAdapter;

/**
 * Basic execution timing middleware replacing ExecutionTimeFilter.
 */
#[\Quiote\Middleware\Attribute\Middleware(phase: 'finalize', priority: -10)]
class ExecutionTimeMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly bool $appendHtmlComment = true) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $start = microtime(true);
        $response = $handler->handle($request);
        $durationMs = (microtime(true) - $start) * 1000;
        if($this->appendHtmlComment && $response instanceof PsrResponseAdapter) {
            $legacy = $response->getLegacy();
            if($legacy->hasContent() && is_string($legacy->getContent())) {
                $legacy->appendContent("\n<!-- exec_time=" . number_format($durationMs, 2) . "ms -->");
            }
        }
        return $response;
    }
}
