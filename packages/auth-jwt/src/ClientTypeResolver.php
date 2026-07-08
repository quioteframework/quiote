<?php
namespace Quiote\Security\Auth;

/**
 * The default {@see ClientTypeResolverInterface}: applies the RFC 9068
 * rule -- `service` when the token's `sub` equals its `client_id`/`azp`
 * (the authority mints machine/client-credentials tokens this way),
 * otherwise `user`. An app wanting different logic replaces this service
 * (registered by {@see JwtAuthPlugin}) rather than toggling a framework flag.
 * @since      1.0.0
 */
final class ClientTypeResolver implements ClientTypeResolverInterface
{
	/**
	 * @param      array<string, mixed> $claims The validated, raw claim set.
	 * @return     ClientType {@see ClientType::Service} when `sub === client_id`/`azp`, otherwise {@see ClientType::User}.
	 * @since      1.0.0
	 */
	public function resolve(array $claims): ClientType
	{
		$subject = $claims['sub'] ?? null;
		$clientId = $claims['client_id'] ?? $claims['azp'] ?? null;

		if($subject !== null && $clientId !== null && $subject === $clientId) {
			return ClientType::Service;
		}

		return ClientType::User;
	}
}
