<?php

declare(strict_types=1);

namespace Quiote\Runtime;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Quiote\Logging\Log;
use Quiote\Middleware\ErrorHandlingMiddleware;
use Quiote\Telemetry\Trace;
use Throwable;

/**
 * Turns a throwable that escaped the middleware pipeline into a response.
 *
 * This is the backstop for a *pre-pipeline* failure -- TelemetryMiddleware and
 * ErrorHandlingMiddleware never got a chance to run, so the exception is
 * recorded on whatever span is active here and then rendered by borrowing
 * ErrorHandlingMiddleware's own renderer, so a request that dies during
 * bootstrap still produces the same RFC 9457 output as one that dies inside
 * an action.
 *
 * It always returns a ResponseInterface and never writes to the SAPI itself.
 * That is what lets a CLI-hosted runtime (RoadRunner, Swoole) emit the error
 * through its own channel; the previous inline version in Kernel fell back to
 * header()+echo, which off-SAPI meant the client got nothing at all.
 */
final class ErrorResponseFactory
{
    public function __construct(private readonly Psr17Factory $psr17 = new Psr17Factory())
    {
    }

    public function fromThrowable(Throwable $e, ?ServerRequestInterface $request = null): ResponseInterface
    {
        Log::for($this)->debug(
            '[Kernel] Uncaught during handle: ' . $e::class . ': ' . $e->getMessage()
            . ' @ ' . $e->getFile() . ':' . $e->getLine()
        );
        Trace::current()->recordException($e)->setStatusError($e->getMessage());

        $request ??= $this->syntheticRequest();

        try {
            $renderer = new ErrorHandlingMiddleware(static function (Throwable $ex, ServerRequestInterface $r): void {
                Log::for(self::class)->debug('[Kernel][late] ' . $ex::class . ': ' . $ex->getMessage());
            });
            return $renderer->renderExceptionResponse($request, $e);
        } catch (Throwable $renderFailure) {
            return $this->lastResort($renderFailure);
        }
    }

    /**
     * When even the renderer blew up there is nothing left to be clever with:
     * a plain 500 that any emitter can send.
     */
    private function lastResort(Throwable $e): ResponseInterface
    {
        try {
            Log::for($this)->error('[Kernel] error rendering failed: ' . $e::class . ': ' . $e->getMessage());
        } catch (Throwable) {
            // Logging itself is unavailable; the response still has to go out.
        }

        return $this->psr17->createResponse(500)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withBody($this->psr17->createStream('Internal Server Error'));
    }

    /** A pre-pipeline failure can predate request construction entirely. */
    private function syntheticRequest(): ServerRequestInterface
    {
        return $this->psr17->createServerRequest('GET', '/error');
    }
}
