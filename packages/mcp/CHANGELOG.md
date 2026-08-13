## [4.0.0] - 2026-08-11

### 🚀 Features

- Declarative plugins.xml/middleware.xml config with attribute-gated plugin activation
- Plain-class MCP attribute discovery + discovery cache warmup
- *(request)* Add #[MapRequest] attribute-based request-DTO mapping
- *(openapi)* Derive an OpenAPI 3.1 document from routes and validators
- *(mcp)* Wire OAuth2 resource-server auth into the MCP HTTP endpoint
- *(context)* [**breaking**] Delete Context::handle() in favour of the PSR-15 handler

### 🐛 Bug Fixes

- Resolve validation, i18n, XML config, and MCP dogfooding findings
- *(mcp)* Fail a tool call that was forwarded instead of returning the login page
- *(packages)* Narrow mixed types across plugin packages and samples/app for PHPStan level 9
- *(mcp)* Stop dropping additionalProperties in tool schemas
- *(security)* Close login CSRF, host-header poisoning and auth timing gaps
- *(security)* Harden cache dir, session expiry, proxy TLS detection and XSLT
- *(packages)* [**breaking**] Require the framework by version, not by "*"
- *(di)* [**breaking**] An omitted scope means what the binding declares, not process lifetime
- *(analysis)* Clear the last 60 PHPStan level 9 errors project-wide

### 💼 Other

- *(composer)* Alias dev-main to 4.0.x-dev across the monorepo

### 🚜 Refactor

- [**breaking**] Resolve plugin names from #[Plugin] attribute, not PluginInterface::name()
- *(packages)* Extend the empty-catch sweep to the first-party packages
- *(context)* [**breaking**] Bind the routing and the controller as lazy factories

### 📚 Documentation

- *(api)* Document every public method and class across the framework

### ⚡ Performance

- Finish the three half-done low-impact items and the shared PSR-17 sweep
