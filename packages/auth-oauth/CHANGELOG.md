## [4.0.0] - 2026-08-11

### 🚀 Features

- Add authentication foundation (form login, HTTP Basic, JWT, OIDC)
- *(auth-oauth)* Fetch OpenID provider metadata via OIDC discovery
- *(session)* [**breaking**] Remove the ext/session storage stack

### 🐛 Bug Fixes

- *(user)* [**breaking**] Only write session state that actually changed
- Repair four latent defects in headers, cache keys, OAuth scopes and rate limiting
- *(security)* Close login CSRF, host-header poisoning and auth timing gaps
- *(packages)* [**breaking**] Require the framework by version, not by "*"

### 💼 Other

- *(composer)* Alias dev-main to 4.0.x-dev across the monorepo

### 🚜 Refactor

- *(context)* [**breaking**] Bind the session manager and bag instead of accessing them off Context

### 📚 Documentation

- *(auth-oauth)* State the real single-use guarantee for OIDC state
