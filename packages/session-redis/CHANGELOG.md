## [4.0.0] - 2026-08-11

### 🚀 Features

- *(redis)* Add Redis backends for cache, queue, session, and rate-limit storage
- *(session)* Ship a slot factory for every session backend

### 🐛 Bug Fixes

- *(packages)* [**breaking**] Require the framework by version, not by "*"

### 💼 Other

- *(composer)* Alias dev-main to 4.0.x-dev across the monorepo

### 🚜 Refactor

- *(packages)* Extend the empty-catch sweep to the first-party packages
- *(session)* [**breaking**] Serialize session payloads through one codec

### 📚 Documentation

- *(api)* Document every public method and class across the framework

### 🧪 Testing

- *(session,queue)* Cover the Redis and object-store backends without Docker
