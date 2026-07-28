# quioteframework/auth-oauth

OAuth 2.1 client (Authorization Code + PKCE, OIDC discovery/ID-token validation) and Client
Credentials (M2M) support for [Quiote](https://github.com/quioteframework/quiote), built on
`league/oauth2-client`. Reuses `quioteframework/auth-jwt`'s `TokenValidatorInterface` for ID-token
signature/`iss`/`aud` verification (one JWT stack, not two) and adds OIDC-specific `nonce`
verification on top. Requires `quioteframework/auth` and `quioteframework/auth-jwt`.

## Install

```
composer require quioteframework/auth-oauth
```

## Discovery

`OidcDiscoveryClient` fetches `{issuer}/.well-known/openid-configuration` (OpenID Connect Discovery
1.0) so one issuer URL replaces five hand-copied endpoint strings. It verifies the document's own
`issuer` against the one you asked for (§4.3), refuses plaintext issuers unless you opt out, and
caches the document in an optional PSR-6 pool — the same kind of pool `auth-jwt`'s JWKS key set
already needs.

```php
$discovery = new OidcDiscoveryClient($psr18Client, $psr17Factory, $cachePool, cacheTtl: 3600);
$document  = $discovery->discover('https://login.example.com/tenant-1/v2.0');

$client = OidcClient::fromDiscovery($document, $clientId, $clientSecret, $redirectUri, ['openid', 'profile']);
$jwksUri = $document->getJwksUri(); // hand to firebase/php-jwt's CachedKeySet for ID-token verification
```

`ClientCredentialsClient::fromDiscovery()` and `IntrospectionClient::fromDiscovery()` are the same
deal for the token and introspection endpoints. Endpoints a flow can't work without throw
`AuthenticationException` at wiring time when the provider doesn't advertise them, rather than
becoming an empty URL later; optional ones (`userinfo`, `introspection`, `revocation`,
`end_session`) return null.

## Enable

No plugin/default registration: `OidcClient`, `OidcAuthenticator`, `ClientCredentialsClient`, and
`IntrospectionClient` all need app-specific endpoints/secrets, so construct and register them
yourself. See `docs/AUTHENTICATION_IMPLEMENTATION_HANDOFF.md` in the main repo for a worked
example.

## License

MIT. See [LICENSE](LICENSE).
