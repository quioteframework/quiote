# quioteframework/auth

Authentication foundation for [Quiote](https://github.com/quioteframework/quiote): password
hashing, user providers, HTTP Basic and form-login authenticators, firewalls, and the
`AuthenticationManager`/`AuthenticationMiddleware` pipeline plumbing. Generalizes to any
credential mechanism via `Quiote\Security\Auth\AuthenticatorInterface`; see `quioteframework/auth-jwt`
(bearer/JWT) and `quioteframework/auth-oauth` (OIDC + Client Credentials) for token-based mechanisms.

## Install

```
composer require quioteframework/auth
```

## Enable

Register `Quiote\Security\Auth\AuthPlugin` in your app's `plugins.*` config (or via
`PluginManager::add()`). Installing it alone changes nothing: the default `FirewallMap` has zero
firewalls, so both authentication middleware are a no-op until you register your own `FirewallMap`
as an earlier DI service — either by hand, or from a `security.xml` file via
`Quiote\Security\Auth\Config\SecurityConfigHandler` + `FirewallFactory`.

## Soft dependencies

- `quioteframework/csrf` — pass a `CsrfManager` to `FormLoginAuthenticator` to verify a CSRF token on login.
- `quioteframework/ratelimit` — pass a `LoginThrottle` to `FormLoginAuthenticator` to throttle repeated failures.

## License

MIT. See [LICENSE](LICENSE).
