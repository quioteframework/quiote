<?php
namespace Quiote\Security\Auth;

/**
 * Distinguishes a human end-user from a machine/service caller, per the
 * RFC 9068 rule applied by {@see ClientTypeResolverInterface}: `Service`
 * when the token's `sub` equals its `client_id`/`azp`, otherwise `User`.
 * A `Service` client type is what flips a request to sessionless (no
 * session started at all) for a stateless firewall.
 * @since      1.0.0
 */
enum ClientType: string
{
	/** A human end-user, authenticated on their own behalf. */
	case User = 'user';

	/** A machine/service caller (e.g. an OAuth Client Credentials token). */
	case Service = 'service';
}
