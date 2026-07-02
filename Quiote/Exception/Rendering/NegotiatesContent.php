<?php
namespace Quiote\Exception\Rendering;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Shared Accept-header negotiation for exception renderers.
 *
 * Deliberately does NOT read the `output_type` request attribute that
 * ContentNegotiationMiddleware sets: ErrorHandlingMiddleware sits outermost
 * in the pipeline and renders using the request it originally received, not
 * whatever downstream middleware produced via withAttribute() before
 * throwing -- PSR-7 immutability means those mutations never propagate back
 * up to the catch site. The `Accept` header is present on the original
 * request unconditionally, so it's what both renderers negotiate on.
 * @since      1.0.0
 */
trait NegotiatesContent
{
	private function wantsJson(ServerRequestInterface $request): bool
	{
		return str_contains(strtolower($request->getHeaderLine('Accept')), 'application/json');
	}

	private function wantsPlainText(ServerRequestInterface $request): bool
	{
		return str_contains(strtolower($request->getHeaderLine('Accept')), 'text/plain');
	}
}
