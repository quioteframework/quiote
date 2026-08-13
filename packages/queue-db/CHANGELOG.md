## [4.0.0] - 2026-08-11

### 🚀 Features

- Add background job/queue subsystem (packages/queue, packages/queue-db)

### 🐛 Bug Fixes

- *(packages)* [**breaking**] Require the framework by version, not by "*"
- *(di)* [**breaking**] An omitted scope means what the binding declares, not process lifetime

### 💼 Other

- *(composer)* Alias dev-main to 4.0.x-dev across the monorepo

### 🚜 Refactor

- *(context)* [**breaking**] Reach the translation and database managers through the container

### 📚 Documentation

- *(api)* Document every public method and class across the framework
