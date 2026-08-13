## [4.0.0] - 2026-08-11

### 🚀 Features

- *(security)* Add CORS, security-headers, and HTTP rate-limit middleware
- *(redis)* Add Redis backends for cache, queue, session, and rate-limit storage

### 🐛 Bug Fixes

- Repair four latent defects in headers, cache keys, OAuth scopes and rate limiting
- *(ratelimit)* Read the trusted end of X-Forwarded-For, not the client's
- *(security)* Close login CSRF, host-header poisoning and auth timing gaps
- *(security)* Harden cache dir, session expiry, proxy TLS detection and XSLT
- *(packages)* [**breaking**] Require the framework by version, not by "*"
- *(di)* [**breaking**] An omitted scope means what the binding declares, not process lifetime

### 💼 Other

- *(composer)* Alias dev-main to 4.0.x-dev across the monorepo

### 🚜 Refactor

- *(context)* [**breaking**] Reach the translation and database managers through the container

### 📚 Documentation

- *(api)* Document every public method and class across the framework

### ⚡ Performance

- Finish the three half-done low-impact items and the shared PSR-17 sweep
