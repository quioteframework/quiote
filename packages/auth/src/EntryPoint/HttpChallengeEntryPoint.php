<?php
namespace Quiote\Security\Auth\EntryPoint;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Quiote\Http\ProblemDetails;
use Quiote\Security\Auth\AuthenticationException;
use Quiote\Security\Auth\EntryPointInterface;

/**
 * The entry point for stateless (token/Basic) firewalls: a `401` RFC 9457
 * Problem Details body plus a `WWW-Authenticate` challenge, matching
 * `Quiote\Mcp\Middleware\McpAuthMiddleware`'s existing shape so API clients
 * see one consistent failure format across the framework.
 * @since      1.0.0
 */
final class HttpChallengeEntryPoint implements EntryPointInterface
{
	/**
	 * @param      string $scheme The `WWW-Authenticate` scheme (e.g. `Bearer`, `Basic`).
	 * @param      ?string $realm An optional `realm` parameter to include in the challenge.
	 * @since      1.0.0
	 */
	public function __construct(private readonly string $scheme = 'Bearer', private readonly ?string $realm = null)
	{
	}

	/**
	 * @param      ServerRequestInterface $request The request that failed authentication.
	 * @param      AuthenticationException $exception The exception the failing authenticator threw.
	 * @return     ResponseInterface A `401` response with a `WWW-Authenticate` header and an RFC 9457 Problem Details body.
	 * @since      1.0.0
	 */
	public function start(ServerRequestInterface $request, AuthenticationException $exception): ResponseInterface
	{
		$problem = ProblemDetails::create(
			status: 401,
			detail: $exception->getMessage(),
			instance: (string) $request->getUri()->getPath(),
		);

		$challenge = $this->scheme;
		if($this->realm !== null) {
			$challenge .= sprintf(' realm="%s"', $this->realm);
		}

		$factory = new Psr17Factory();

		return $factory->createResponse(401)
			->withHeader('Content-Type', ProblemDetails::MEDIA_TYPE)
			->withHeader('WWW-Authenticate', $challenge)
			->withBody($factory->createStream($problem->toJson()));
	}
}
