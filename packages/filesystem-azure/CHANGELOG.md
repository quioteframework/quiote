## [4.0.0] - 2026-08-11

### 🚀 Features

- *(filesystem)* Add general-purpose filesystem abstraction
- *(filesystem)* Read cloud file metadata over HEAD

### 🐛 Bug Fixes

- *(packages)* [**breaking**] Require the framework by version, not by "*"
- *(di)* [**breaking**] An omitted scope means what the binding declares, not process lifetime

### 💼 Other

- *(composer)* Alias dev-main to 4.0.x-dev across the monorepo

### 🚜 Refactor

- *(packages)* [**breaking**] Extract the cloud clients into cloud-* packages
- *(filesystem)* [**breaking**] Segregate listing from the filesystem contract
- *(storage)* [**breaking**] Give the object stores one contract and one implementation

### 📚 Documentation

- *(api)* Document every public method and class across the framework

### ⚙️ Miscellaneous Tasks

- *(packages)* Credit the cloud-* packages in filesystem-* metadata
