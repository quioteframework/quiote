## [4.0.0] - 2026-08-11

### 🚀 Features

- Add authentication foundation (form login, HTTP Basic, JWT, OIDC)
- *(session)* [**breaking**] Remove the ext/session storage stack

### 🐛 Bug Fixes

- *(csrf)* Validate against the real session cookie and a proven credential
- *(auth)* Close the login enumeration oracle and throttle per client
- *(auth)* Reject unusable firewall patterns and match the normalized path
- *(auth)* Parse the Authorization scheme case-insensitively
- *(security)* Close login CSRF, host-header poisoning and auth timing gaps
- *(security)* Harden cache dir, session expiry, proxy TLS detection and XSLT
- *(packages)* [**breaking**] Require the framework by version, not by "*"
- *(di)* [**breaking**] An omitted scope means what the binding declares, not process lifetime
- *(auth)* Carry a stateless passport's validated claims onto SecurityUser
- *(auth)* Scope the token-derived marker to the request that presented the token

### 💼 Other

- *(composer)* Alias dev-main to 4.0.x-dev across the monorepo

### 🚜 Refactor

- *(packages)* Extend the empty-catch sweep to the first-party packages
- *(config)* [**breaking**] Serialize a compiled configuration in the cache, not in the handler
- *(context)* [**breaking**] Bind the session manager and bag instead of accessing them off Context
- *(context)* [**breaking**] Bind the routing and the controller as lazy factories
- *(context)* [**breaking**] Bind the user, and let CurrentUser resolve it

### ⚡ Performance

- Finish the three half-done low-impact items and the shared PSR-17 sweep
