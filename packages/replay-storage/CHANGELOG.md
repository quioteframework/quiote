## [4.1.0] - 2026-09-08

### 🚀 Features

- *(console)* Add env:list, backed by pluggable environment sources

### 📚 Documentation

- *(changelog)* Adopt stable-only changelog entries, clean up RC noise

### ⚙️ Miscellaneous Tasks

- Fix dev-main branch-alias for every package with a release

## [4.0.0] - 2026-08-26

### 🚀 Features

- *(replay)* Add an object-store-backed cassette store for Azure Blob
- *(replay)* Add a cassette-index chain to resolve a bare id to a cassette

### 🐛 Bug Fixes

- *(replay)* One timestamp rule, resilient index chain, and far fewer store round trips
- *(replay)* Treat a cassette as untrusted input on the replay path

### 📚 Documentation

- *(replay)* Remove internal plan-doc citations from code comments
- *(replay)* Add changelogs for the eight replay packages
