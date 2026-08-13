## [4.0.0] - 2026-08-11

### 🚀 Features

- *(security)* Add CORS, security-headers, and HTTP rate-limit middleware

### 🐛 Bug Fixes

- *(cors)* Never emit a wildcard origin alongside credentials
- *(cors)* [**breaking**] Refuse a wildcard origin combined with credentials
- *(security)* Harden cache dir, session expiry, proxy TLS detection and XSLT
- *(packages)* [**breaking**] Require the framework by version, not by "*"

### 💼 Other

- *(composer)* Alias dev-main to 4.0.x-dev across the monorepo

### 📚 Documentation

- *(api)* Document every public method and class across the framework

### ⚡ Performance

- Finish the three half-done low-impact items and the shared PSR-17 sweep
