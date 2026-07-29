<?php
namespace Quiote\Security\Auth;

use Quiote\Context;

/**
 * Persists a single in-flight {@see OidcAuthorizationState} in the
 * session-backed `Context` storage, keyed by its own `state` value so a
 * concurrent second login attempt in another tab doesn't clobber the
 * first. {@see consume()} removes the entry on read.
 *
 * Single-use is enforced per request, not atomically. The removal in
 * {@see consume()} lands in the in-memory session bag and only becomes durable
 * when the session is persisted at the end of the request, and nothing locks the
 * session across that read-modify-write. Two callbacks carrying the same `state`
 * that are genuinely in flight at the same moment can therefore both load the
 * session row, both observe the entry and both proceed; the later persist wins.
 *
 * That gap is left open deliberately rather than papered over. Closing it needs
 * either session-level locking -- which {@see \Quiote\Session\SessionPersistenceInterface}
 * has no concept of (no lock/unlock, no compare-and-swap) and which several of
 * the shipped backends could not implement at all, object stores in particular --
 * or moving OIDC state out of the session onto its own store with an atomic
 * compare-and-delete. Both are larger changes than the residual risk justifies:
 *
 *  - PKCE already binds the code exchange to a verifier the attacker does not
 *    hold, so a replayed `state` alone does not yield tokens.
 *  - The authorization server is required to reject a reused authorization code,
 *    which is the actual single-use guarantee for the credential that matters.
 *  - Winning the race additionally requires already possessing a valid code.
 *
 * So treat this as defence in depth with a known bound, not as an atomic
 * one-shot. If an application's threat model needs the stronger property, supply
 * a storage implementation with a real atomic take operation.
 * @since      1.0.0
 */
final class OidcStateStorage
{
	private const NAMESPACE_PREFIX = 'org.quiote.security.auth.oauth.oidc_state.';

	/**
	 * @param      Context $context The current application context, used to reach its session.
	 * @since      1.0.0
	 */
	public function __construct(private readonly Context $context)
	{
	}

	/**
	 * @param      OidcAuthorizationState $state The state to persist, keyed by its own `state` value.
	 * @return     void
	 * @since      1.0.0
	 */
	public function store(OidcAuthorizationState $state): void
	{
		$this->context->getSessionBag()->set(self::NAMESPACE_PREFIX . $state->getState(), [
			'state' => $state->getState(),
			'pkce_verifier' => $state->getPkceVerifier(),
			'nonce' => $state->getNonce(),
		]);
	}

	/**
	 * Retrieve and remove the stored state for $state, or null if none
	 * exists (already consumed, expired session, or forged value).
	 *
	 * Not atomic across concurrent requests -- see the class docblock for what is
	 * and is not guaranteed, and why.
	 *
	 * The lookup is by key rather than by comparing the submitted value against a
	 * stored one, which is what keeps it free of a timing side channel: there is no
	 * secret-dependent comparison here to leak. (The `hash_equals()` in
	 * {@see OidcAuthenticator} is therefore belt-and-braces on a value it just used
	 * as the lookup key, not the control doing the work.)
	 * @param      string $state The `state` value received on the callback.
	 * @return     ?OidcAuthorizationState The stored state, or null if none exists for $state.
	 * @since      1.0.0
	 */
	public function consume(string $state): ?OidcAuthorizationState
	{
		$key = self::NAMESPACE_PREFIX . $state;
		$bag = $this->context->getSessionBag();
		$data = $bag->get($key);
		$bag->remove($key);

		if(!is_array($data) || !isset($data['state'], $data['pkce_verifier'], $data['nonce'])
			|| !is_string($data['state']) || !is_string($data['pkce_verifier']) || !is_string($data['nonce'])) {
			return null;
		}

		return new OidcAuthorizationState($data['state'], $data['pkce_verifier'], $data['nonce']);
	}
}
