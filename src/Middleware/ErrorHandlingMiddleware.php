<?php
namespace Agavi\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Nyholm\Psr7\Response;
use Agavi\Execution\ExecutionState;
use Throwable;

/**
 * Catches unhandled throwables from downstream middleware/action dispatch and
 * produces a generic 500 (or mapped) response. Minimal for Phase 2; can be
 * extended to perform content negotiation (HTML/JSON) and structured logging.
 */
class ErrorHandlingMiddleware implements MiddlewareInterface
{
    /** @var callable|null */
    private $logger;

    /** @param callable(Throwable $e, ServerRequestInterface $r):void|null $logger */
    public function __construct(?callable $logger = null)
    { $this->logger = $logger; }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch(Throwable $e) {
            if($this->logger) { try { ($this->logger)($e, $request); } catch(Throwable) { /* swallow logging errors */ } }
            $exec = $request->getAttribute(ExecutionState::class);
            if(!$exec instanceof ExecutionState) {
                $exec = new ExecutionState();
                $request = $request->withAttribute(ExecutionState::class, $exec);
            }
            $exec->viewName = 'Error';
            $exec->viewModule = $exec->viewModule ?? 'Default';
            // Basic text response; future: negotiate using Accept header.
            $body = "Internal Server Error";
            $status = 500;
            // Simple mapping examples (expand later):
            $map = [
                \InvalidArgumentException::class => 400,
                \DomainException::class => 422,
            ];
            foreach($map as $class => $code) {
                if($e instanceof $class) { $status = $code; break; }
            }
            // Attach a simple header with exception type for debug mode (guarded)
            if(getenv('AGAVI_DEBUG')) {
                return new Response($status, [
                    'Content-Type' => 'text/plain; charset=utf-8',
                    'X-Agavi-Error-Type' => $e::class,
                ], $body."\n".$e->getMessage());
            }
            return new Response($status, ['Content-Type' => 'text/plain; charset=utf-8'], $body);
        }
    }
}
