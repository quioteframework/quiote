# quioteframework/auth-jwt

Bearer/JWT resource-server authentication for [Quiote](https://github.com/quioteframework/quiote),
built on `firebase/php-jwt`. Verifies a JWS (shared HS256 secret, or RS256/ES256 via a JWKS-backed
`CachedKeySet`), enforces `iss`/`aud`, and derives human-vs-machine client type per RFC 9068.
Requires `quioteframework/auth` for the shared `AuthenticatorInterface`/`Passport`/`UserProviderInterface`
contracts.

## Install

```
composer require quioteframework/auth-jwt
```

## Enable

Register `Quiote\Security\Auth\JwtAuthPlugin` (registers the default `ClientTypeResolverInterface`).
`TokenValidatorInterface`/`BearerTokenAuthenticator` need app-specific secrets (issuer, audience,
JWKS URI or shared key), so construct and register those yourself — typically alongside a
`FirewallMap` built with `quioteframework/auth`'s `FirewallFactory`.

## License

MIT. See [LICENSE](LICENSE).
