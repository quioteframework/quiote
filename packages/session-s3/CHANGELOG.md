## [4.0.0] - 2026-08-11

### 🚀 Features

- *(session)* Ship a slot factory for every session backend

### 🐛 Bug Fixes

- *(packages)* [**breaking**] Require the framework by version, not by "*"
- *(analysis)* Clear the last 60 PHPStan level 9 errors project-wide

### 💼 Other

- *(composer)* Alias dev-main to 4.0.x-dev across the monorepo

### 🚜 Refactor

- *(packages)* [**breaking**] Extract the cloud clients into cloud-* packages
- *(session)* [**breaking**] Serialize session payloads through one codec
- *(storage)* [**breaking**] Give the object stores one contract and one implementation

### 📚 Documentation

- *(api)* Document every public method and class across the framework
