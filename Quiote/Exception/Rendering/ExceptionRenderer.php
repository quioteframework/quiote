<?php
namespace Quiote\Exception\Rendering;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * Turns a caught Throwable into a client-facing PSR-7 response. This is the
 * seam ErrorHandlingMiddleware delegates to -- it is the ONE catch point in
 * the framework; a renderer only decides how to present what was caught, it
 * never does its own catching.
 *
 * Implementations must be worker-mode safe: no echo, no exit(), no reliance
 * on superglobals (use $request instead -- $_SERVER/$_GET can be stale or
 * empty in a persistent worker), and must return a real PSR-7 response
 * rather than writing output directly.
 * @since      1.0.0
 */
interface ExceptionRenderer
{
	/**
	 * @param Throwable $e The caught exception (top of the chain).
	 * @param ServerRequestInterface $request The request that triggered it.
	 * @param int $status The HTTP status already decided by the middleware
	 *                     (e.g. 400 for InvalidArgumentException, 500 default).
	 * @param string|null $correlationId Already-extracted correlation id, if any.
	 */
	public function render(Throwable $e, ServerRequestInterface $request, int $status, ?string $correlationId): ResponseInterface;
}
