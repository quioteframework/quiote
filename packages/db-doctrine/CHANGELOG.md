## [4.1.0] - 2026-08-20

### 🚀 Features

- *(replay)* Wire Doctrine/Eloquent/Cycle DB effects into live requests
## [4.0.0] - 2026-08-11

### 🚀 Features

- Declarative plugins.xml/middleware.xml config with attribute-gated plugin activation
- Add getPdo() to all database adapters for raw SQL access

### 🐛 Bug Fixes

- *(packages)* Narrow mixed types across plugin packages and samples/app for PHPStan level 9
- *(packages)* [**breaking**] Require the framework by version, not by "*"
- *(db)* Make reset() honour the Database teardown contract

### 💼 Other

- *(composer)* Alias dev-main to 4.0.x-dev across the monorepo

### 🚜 Refactor

- [**breaking**] Resolve plugin names from #[Plugin] attribute, not PluginInterface::name()
- *(packages)* Extend the empty-catch sweep to the first-party packages

### 📚 Documentation

- *(api)* Document every public method and class across the framework

### 🧪 Testing

- *(db)* Cover the adapter parameter mapping and worker lifecycle
