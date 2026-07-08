<?php
namespace Quiote\Security\Auth;

/**
 * Derives {@see ClientType} from a set of already-validated token claims.
 * The default implementation applies the RFC 9068 rule (`service` when
 * `sub === client_id`/`azp`, else `user`); an app that needs different
 * logic swaps this service rather than toggling a framework flag.
 * @since      1.0.0
 */
interface ClientTypeResolverInterface
{
	/**
	 * @param      array<string, mixed> $claims The validated, raw claim set (pre-{@see TokenClaims}).
	 * @return     ClientType Human vs. machine, per RFC 9068.
	 * @since      1.0.0
	 */
	public function resolve(array $claims): ClientType;
}
