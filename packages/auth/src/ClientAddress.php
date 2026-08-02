<?php
namespace Quiote\Security\Auth;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The connecting peer's address, for use as a throttle key.
 *
 * Deliberately `REMOTE_ADDR` and never a client-supplied forwarding header
 * (`X-Forwarded-For`, `Forwarded`, ...): a spoofable key lets an attacker
 * present a fresh one on every request, which is indistinguishable from no
 * throttling at all. A deployment that genuinely sits behind a trusted proxy
 * has to make `REMOTE_ADDR` correct at the proxy boundary rather than have
 * every authenticator second-guess the header.
 *
 * Null when the peer is unknown (CLI, a synthesized request, some worker
 * runtimes). Callers must treat that as "no client key available" and fall back
 * to their identifier-scoped key alone, never as a shared literal -- bucketing
 * every unknown-peer attempt under one key would let one caller exhaust the
 * allowance for all of them.
 * @since      3.0.4
 */
final class ClientAddress
{
	private function __construct()
	{
	}

	/**
	 * @param      ServerRequestInterface $request The incoming request.
	 * @return     ?string The peer address, or null when it is unknown.
	 * @since      3.0.4
	 */
	public static function fromRequest(ServerRequestInterface $request): ?string
	{
		$remote = $request->getServerParams()['REMOTE_ADDR'] ?? null;

		return is_string($remote) && $remote !== '' ? $remote : null;
	}
}
