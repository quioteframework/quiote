## [4.0.0] - 2026-08-11

### 🚀 Features

- Declarative plugins.xml/middleware.xml config with attribute-gated plugin activation
- *(session)* [**breaking**] Remove the ext/session storage stack

### 🐛 Bug Fixes

- *(user)* [**breaking**] Only write session state that actually changed
- *(csrf)* Validate against the real session cookie and a proven credential
- *(security)* Close login CSRF, host-header poisoning and auth timing gaps
- *(security)* Harden cache dir, session expiry, proxy TLS detection and XSLT
- *(packages)* [**breaking**] Require the framework by version, not by "*"
- *(config)* A factory being switched off is not the same as being optional

### 💼 Other

- *(composer)* Alias dev-main to 4.0.x-dev across the monorepo

### 🚜 Refactor

- [**breaking**] Resolve plugin names from #[Plugin] attribute, not PluginInterface::name()
- *(packages)* Extend the empty-catch sweep to the first-party packages
- *(context)* [**breaking**] Bind the session manager and bag instead of accessing them off Context
- *(context)* [**breaking**] Bind the routing and the controller as lazy factories

### 📚 Documentation

- *(api)* Document every public method and class across the framework

### ⚡ Performance

- Finish the three half-done low-impact items and the shared PSR-17 sweep

### 🧪 Testing

- *(csrf)* Run the CSRF suite against a real session manager
- *(csrf)* State the CSRF guarantee as an adversary table
