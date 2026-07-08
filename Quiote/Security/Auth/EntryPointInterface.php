<?php
namespace Quiote\Security\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Produces the failure response for a firewall when authentication is
 * required but absent/invalid: a `LoginRedirectEntryPoint` (reuses the
 * existing `ForwardService` login flow) for session/form firewalls, or an
 * `HttpChallengeEntryPoint` (401 + `WWW-Authenticate`, RFC 7807 JSON,
 * matching `Quiote\Mcp\Middleware\McpAuthMiddleware`) for token firewalls.
 * @since      1.0.0
 */
interface EntryPointInterface
{
	/**
	 * @param      ServerRequestInterface $request The request that failed authentication.
	 * @param      AuthenticationException $exception The exception the failing authenticator threw.
	 * @return     ResponseInterface The response to send instead of continuing the pipeline.
	 * @since      1.0.0
	 */
	public function start(ServerRequestInterface $request, AuthenticationException $exception): ResponseInterface;
}
