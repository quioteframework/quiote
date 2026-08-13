## [4.0.0] - 2026-08-11

### 🚀 Features

- *(redis)* Add Redis backends for cache, queue, session, and rate-limit storage

### 🐛 Bug Fixes

- *(packages)* [**breaking**] Require the framework by version, not by "*"
- *(di)* [**breaking**] An omitted scope means what the binding declares, not process lifetime

### 💼 Other

- *(composer)* Alias dev-main to 4.0.x-dev across the monorepo

### 📚 Documentation

- *(api)* Document every public method and class across the framework

### 🧪 Testing

- *(session,queue)* Cover the Redis and object-store backends without Docker
