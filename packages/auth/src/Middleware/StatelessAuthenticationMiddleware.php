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
 * Runs stateless firewalls' authenticator chains (HTTP Basic, and -- once
 * `packages/auth-jwt` is installed -- bearer/JWT) before routing and before
 * `Quiote\Middleware\SessionMiddleware`, matching firewalls by request
 * path. Registered by {@see \Quiote\Security\Auth\AuthPlugin} with an
 * explicit `before: Quiote\Middleware\SessionMiddleware::class` anchor
 * rather than relying on a bare phase/priority: `MiddlewarePhase::ORDER`
 * places the `bootstrap` phase (where `SessionMiddleware` sits at priority
 * 900) ahead of the `pre_routing`/`pre` phases unconditionally, so only an
 * explicit edge guarantees this runs first -- letting a machine-client
 * token signal "no session" (via the `auth.sessionless` request attribute)
 * before session startup.
 * @since      1.0.0
 */
final class StatelessAuthenticationMiddleware implements MiddlewareInterface
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
	 * @return     ResponseInterface The next middleware's response, or the firewall's entry-point response on an invalid credential.
	 * @since      1.0.0
	 */
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$firewall = $this->firewalls->match((string) $request->getUri()->getPath());
		if($firewall === null || !$firewall->isStateless()) {
			return $handler->handle($request);
		}

		try {
			$passport = $this->manager->authenticate($request, $firewall);
		} catch(AuthenticationException $exception) {
			return $firewall->getEntryPoint()->start($request, $exception);
		}

		$isServiceToken = $passport?->getClaims()?->isService() ?? false;
		if($firewall->isSessionless() || $isServiceToken) {
			$request = $request->withAttribute('auth.sessionless', true);
		}

		return $handler->handle($request);
	}
}
