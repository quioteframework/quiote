<?php
namespace Quiote\Security\Auth\EntryPoint;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Quiote\Security\Auth\AuthenticationException;
use Quiote\Security\Auth\EntryPointInterface;

/**
 * The entry point for the session/form-login firewall: a `302` redirect
 * back to the login path. Complements, and does not duplicate, the
 * existing anonymous-access forward already handled by
 * `Quiote\Middleware\SecurityMiddleware` via `SecurityService::decide()` +
 * `ForwardService` (unchanged -- authentication and authorization stay
 * separate concerns) -- this entry point only fires when a login
 * *attempt* itself fails (e.g. a bad password on the login POST), not on
 * plain unauthenticated browsing.
 * @since      1.0.0
 */
final class LoginRedirectEntryPoint implements EntryPointInterface
{
	/**
	 * @param      string $loginPath The path to redirect back to.
	 * @param      string $errorQueryParameter The query parameter appended to signal a failed attempt (e.g. `?error=1`).
	 * @since      1.0.0
	 */
	public function __construct(private readonly string $loginPath = '/login', private readonly string $errorQueryParameter = 'error')
	{
	}

	/**
	 * @param      ServerRequestInterface $request The request that failed authentication (the login POST).
	 * @param      AuthenticationException $exception The exception the failing authenticator threw.
	 * @return     ResponseInterface A `302` redirect back to the login path with the error query parameter set.
	 * @since      1.0.0
	 */
	public function start(ServerRequestInterface $request, AuthenticationException $exception): ResponseInterface
	{
		$separator = str_contains($this->loginPath, '?') ? '&' : '?';
		$location = $this->loginPath . $separator . $this->errorQueryParameter . '=1';

		$factory = new Psr17Factory();

		return $factory->createResponse(302)->withHeader('Location', $location);
	}
}
