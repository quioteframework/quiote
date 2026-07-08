<?php
namespace Quiote\Security\Auth;

use Quiote\Context;

/**
 * Persists a single in-flight {@see OidcAuthorizationState} in the
 * session-backed `Context` storage, keyed by its own `state` value so a
 * concurrent second login attempt in another tab doesn't clobber the
 * first. {@see consume()} removes the entry on read, so a state/PKCE pair
 * can be used exactly once (replay protection).
 * @since      1.0.0
 */
final class OidcStateStorage
{
	private const NAMESPACE_PREFIX = 'org.quiote.security.auth.oauth.oidc_state.';

	/**
	 * @param      Context $context The current application context, used to reach its session-backed storage.
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
		$this->context->getStorage()->store(self::NAMESPACE_PREFIX . $state->getState(), [
			'state' => $state->getState(),
			'pkce_verifier' => $state->getPkceVerifier(),
			'nonce' => $state->getNonce(),
		]);
	}

	/**
	 * Retrieve and remove the stored state for $state, or null if none
	 * exists (already consumed, expired session, or forged value).
	 * @param      string $state The `state` value received on the callback.
	 * @return     ?OidcAuthorizationState The stored state, or null if none exists for $state.
	 * @since      1.0.0
	 */
	public function consume(string $state): ?OidcAuthorizationState
	{
		$key = self::NAMESPACE_PREFIX . $state;
		$storage = $this->context->getStorage();
		$data = $storage->retrieve($key);
		$storage->remove($key);

		if(!is_array($data) || !isset($data['state'], $data['pkce_verifier'], $data['nonce'])
			|| !is_string($data['state']) || !is_string($data['pkce_verifier']) || !is_string($data['nonce'])) {
			return null;
		}

		return new OidcAuthorizationState($data['state'], $data['pkce_verifier'], $data['nonce']);
	}
}
