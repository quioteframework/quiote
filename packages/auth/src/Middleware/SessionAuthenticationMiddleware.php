<?php
namespace Quiote\Security\Auth\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quiote\Security\Auth\AuthenticationException;
use Quiote\Security\Auth\AuthenticationManager;
use Quiote\Security\Auth\FirewallMap;

/**
 * Runs the session/form-login firewall's authenticator chain in
 * `before_action`, after `Quiote\Middleware\RoutingMiddleware` (so
 * `SecurityUser` is already rehydrated) and before
 * `Quiote\Middleware\SecurityMiddleware` (so a successful login is visible
 * to the authZ decision made later in the same request). Registered by
 * {@see \Quiote\Security\Auth\AuthPlugin} with explicit `after:`/`before:`
 * anchors rather than a bare phase/priority, so its position stays correct
 * regardless of how other middleware are reordered.
 * @since      1.0.0
 */
final class SessionAuthenticationMiddleware implements MiddlewareInterface
{
	/**
	 * @param      FirewallMap $firewalls The configured firewalls, matched by request path.
	 * @param      AuthenticationManager $manager Runs the matched firewall's authenticator chain.
	 * @since      1.0.0
	 */
	public function __construct(
		private readonly FirewallMap $firewalls,
		private readonly AuthenticationManager $manager,
	) {
	}

	/**
	 * @param      ServerRequestInterface $request The incoming request.
	 * @param      RequestHandlerInterface $handler The next middleware in the pipeline.
	 * @return     ResponseInterface The next middleware's response, or the firewall's entry-point response on a failed login attempt.
	 * @since      1.0.0
	 */
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$firewall = $this->firewalls->match((string) $request->getUri()->getPath());
		if($firewall === null || $firewall->isStateless()) {
			return $handler->handle($request);
		}

		try {
			$this->manager->authenticate($request, $firewall);
		} catch(AuthenticationException $exception) {
			return $firewall->getEntryPoint()->start($request, $exception);
		}

		return $handler->handle($request);
	}
}
