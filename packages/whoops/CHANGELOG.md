## [4.0.0] - 2026-08-11

### 🚀 Features

- Declarative plugins.xml/middleware.xml config with attribute-gated plugin activation

### 🐛 Bug Fixes

- *(packages)* Narrow mixed types across plugin packages and samples/app for PHPStan level 9
- *(packages)* [**breaking**] Require the framework by version, not by "*"

### 💼 Other

- *(composer)* Alias dev-main to 4.0.x-dev across the monorepo

### 🚜 Refactor

- [**breaking**] Resolve plugin names from #[Plugin] attribute, not PluginInterface::name()

### 📚 Documentation

- *(api)* Document every public method and class across the framework
