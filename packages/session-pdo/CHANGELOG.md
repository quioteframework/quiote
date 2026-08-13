## [4.0.0] - 2026-08-11

### 🚀 Features

- *(session)* [**breaking**] Remove the ext/session storage stack
- *(session)* Ship a slot factory for every session backend

### 🐛 Bug Fixes

- *(storage)* Release the read cursor in the PDO session backends
- *(security)* Harden cache dir, session expiry, proxy TLS detection and XSLT
- *(packages)* [**breaking**] Require the framework by version, not by "*"

### 💼 Other

- *(composer)* Alias dev-main to 4.0.x-dev across the monorepo

### 🚜 Refactor

- *(packages)* [**breaking**] Extract the cloud clients into cloud-* packages
- *(packages)* Extend the empty-catch sweep to the first-party packages
- *(session)* [**breaking**] Serialize session payloads through one codec
- *(context)* [**breaking**] Take seven accessors off Context, and let the container carry the types

### 📚 Documentation

- *(api)* Document every public method and class across the framework
