## [4.1.0] - 2026-09-08

### 🚀 Features

- *(console)* Add env:list, backed by pluggable environment sources

### 📚 Documentation

- *(changelog)* Adopt stable-only changelog entries, clean up RC noise

### ⚙️ Miscellaneous Tasks

- Fix dev-main branch-alias for every package with a release

## [4.0.1] - 2026-08-31

### 🐛 Bug Fixes

- *(replay-azure)* Fall back to the framework's HTTP client factory
## [4.0.0] - 2026-08-26

### 🚀 Features

- *(replay)* Add an object-store-backed cassette store for Azure Blob
- *(replay)* Add a cassette-index chain to resolve a bare id to a cassette

### 🐛 Bug Fixes

- *(replay)* Select the cassette store by config, not by plugin load order

### 📚 Documentation

- *(replay)* Remove internal plan-doc citations from code comments
- *(replay)* Add changelogs for the eight replay packages
