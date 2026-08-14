## [4.0.1] - 2026-08-13

### 🐛 Bug Fixes

- *(db-propulsion)* Allow Propulsion 3.x alongside 2.x
## [4.0.0] - 2026-08-13

### 🚀 Features

- Declarative plugins.xml/middleware.xml config with attribute-gated plugin activation
- Add getPdo() to all database adapters for raw SQL access
- *(release)* Version packages independently of the framework from v4.0.0

### 🐛 Bug Fixes

- *(packages)* [**breaking**] Require the framework by version, not by "*"
- *(analysis)* Clear the last 60 PHPStan level 9 errors project-wide
- *(db)* Make reset() honour the Database teardown contract
- *(db-propulsion)* Stop discarding live connections on every initialize()
- *(db-propulsion)* Resolve the connection fresh on every getConnection()/getResource() call

### 💼 Other

- *(composer)* Alias dev-main to 4.0.x-dev across the monorepo

### 🚜 Refactor

- [**breaking**] Resolve plugin names from #[Plugin] attribute, not PluginInterface::name()

### 📚 Documentation

- *(api)* Document every public method and class across the framework

### 🧪 Testing

- *(db-propulsion)* Stop naming a connection class the adapter already picks
- *(db)* Cover the adapter parameter mapping and worker lifecycle
