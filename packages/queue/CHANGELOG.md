## [4.0.0] - 2026-08-11

### 🚀 Features

- Add background job/queue subsystem (packages/queue, packages/queue-db)

### 🐛 Bug Fixes

- *(queue)* Verify the job class before constructing it
- *(packages)* [**breaking**] Require the framework by version, not by "*"
- *(di)* [**breaking**] An omitted scope means what the binding declares, not process lifetime
- *(analysis)* Clear the last 60 PHPStan level 9 errors project-wide

### 💼 Other

- *(composer)* Alias dev-main to 4.0.x-dev across the monorepo

### 📚 Documentation

- *(api)* Document every public method and class across the framework

### 🧪 Testing

- Stop tests leaking global state into each other
