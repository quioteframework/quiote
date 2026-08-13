## [4.0.0] - 2026-08-11

### 🚀 Features

- Add authentication foundation (form login, HTTP Basic, JWT, OIDC)

### 🐛 Bug Fixes

- *(auth-jwt)* Claim a bare Bearer header and parse the scheme per RFC 9110
- *(security)* Close login CSRF, host-header poisoning and auth timing gaps
- *(packages)* [**breaking**] Require the framework by version, not by "*"
- *(di)* [**breaking**] An omitted scope means what the binding declares, not process lifetime

### 💼 Other

- *(composer)* Alias dev-main to 4.0.x-dev across the monorepo
