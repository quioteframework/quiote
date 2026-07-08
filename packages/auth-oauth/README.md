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

## Enable

No plugin/default registration: `OidcClient`, `OidcAuthenticator`, `ClientCredentialsClient`, and
`IntrospectionClient` all need app-specific endpoints/secrets, so construct and register them
yourself. See `docs/AUTHENTICATION_IMPLEMENTATION_HANDOFF.md` in the main repo for a worked
example.

## License

MIT. See [LICENSE](LICENSE).
