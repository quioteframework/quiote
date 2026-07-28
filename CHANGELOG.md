## [2.0.1] - 2026-07-28

### 🐛 Bug Fixes

- *(scheduler)* Rebind the default schedule per test to stop cross-test leakage
- *(http-client)* Drop redundant Before/After attributes on setUp/tearDown
## [2.0.0] - 2026-07-28

### 🚀 Features

- Let renderers author their own scaffold starter template
- [**breaking**] Drop legacy 0.11/1.0 config envelope migration
- Add background job/queue subsystem (packages/queue, packages/queue-db)
- *(session)* Add file-backed SessionPersistenceInterface backend
- *(request)* Add #[MapRequest] attribute-based request-DTO mapping
- *(security)* Add CORS, security-headers, and HTTP rate-limit middleware
- *(testing)* Add fluent HTTP test client
- *(scheduler)* Add cron-expression schedule:run command
- *(http)* Add Server-Sent Events streaming support
- *(redis)* Add Redis backends for cache, queue, session, and rate-limit storage
- *(console)* Add make:* generators and serve command
- *(filesystem)* Add general-purpose filesystem abstraction
- *(worker-roadrunner)* Add a RoadRunner worker runtime
- *(worker-swoole)* Add a Swoole worker runtime
- *(runtime)* Verify the new worker runtimes against real servers, and finish the claim
- *(openapi)* Derive an OpenAPI 3.1 document from routes and validators
- *(auth-oauth)* Fetch OpenID provider metadata via OIDC discovery

### 🐛 Bug Fixes

- Remove dead XML routing config path
- Reset routing and translation manager state between worker requests
- Guard session redirect grace window against backward wall-clock steps
- Guard slot cache TTL against backward wall-clock steps
- Gettext plural forms never selected the plural msgstr
- *(session)* Honour the documented auto_start parameter in startup()

### 🚜 Refactor

- Resolve PHPStan level 9 findings in WebResponse and View
- *(runtime)* [**breaking**] Replace the worker adapter with a runtime-agnostic contract

### 📚 Documentation

- Fix incorrect URLs in README
- Record the worker-runtime docs gap in DOCS_TODO
- Track the perf audit plan and the production-flags reference

### ⚡ Performance

- Preload Quiote core classes into OPcache for FrankenPHP workers
- Memoize config-format resolution, cache gettext catalogs, hoist view layer attrs
- Cache ICU formatters, bulk request-param mutation, trim slot dispatch
- Memoize header/locale/layer helpers, gate setRequest debug string
- Bulk-merge form-population params, gate DOM repopulation to actual HTML forms
- Native session upsert, lazy session_start, clean anonymous sessions
- Cache compiled APCu validator config as a reusable closure
- Add core.config_check_freshness prod trust-cache mode
- Dump routing IR as a compiled artifact to skip the live scan
- Cache validation, model, session, RBAC, logging, event, translation and template hot paths
- Fix the low-impact items from the framework-wide audit (18-28)
- Finish the three half-done low-impact items and the shared PSR-17 sweep

### ⚙️ Miscellaneous Tasks

- Add new packages to subtree splitter
## [1.2.4] - 2026-07-10

### 🐛 Bug Fixes

- Resolve per-output-type template files in app introspection
## [1.2.3] - 2026-07-09

### 🐛 Bug Fixes

- Switch local packages back to @dev in composer.json
## [1.2.2] - 2026-07-09

### 🐛 Bug Fixes

- Composer still referenced @dev versions of split packages
## [1.2.1] - 2026-07-09

### 🐛 Bug Fixes

- Subtree-split needs to have email and user configured
## [1.2.0] - 2026-07-09

### 🐛 Bug Fixes

- Re-tag release as v1.2.0
## [1.0.0] - 2026-07-09

### 🚀 Features

- Declarative plugins.xml/middleware.xml config with attribute-gated plugin activation
- Add getPdo() to all database adapters for raw SQL access
- Plain-class MCP attribute discovery + discovery cache warmup
- Accept a flat locale-keyed shape in SimpleTranslator
- Raise PHPStan to level 8 across framework and test suite
- Add array-shape schema validation and position tracking for all config types
- Compile route/module/triad introspection artifact for the VS Code extension
- Add authentication foundation (form login, HTTP Basic, JWT, OIDC)
- Resolve MISSING_TEMPLATE per execute*() method and output type
- Skip MISSING_TEMPLATE automatically when a return type proves it

### 🐛 Bug Fixes

- *(validation)* Align pruning with test fixtures
- *(validation)* Whitelist declared request params
- PHPStan level 6 errors and remove dead defensive checks
- Wire TimingMiddleware/TraceMiddleware header emission through Config
- Register manual validators in xmlOnlyValidate() to fix strict-mode false rejection
- [**breaking**] Close strict-mode bypasses in getParameters(), isSimple(), and headers
- [**breaking**] Default custom middleware placement to after ValidationMiddleware
- Record an incident when EmailValidator rejects a malformed address
- Resolve validation, i18n, XML config, and MCP dogfooding findings
- Thread config_handlers.xml validations through the FormatDriver path
- Stop auto-whitelisting promoted route params without a validator
- Honor auth.sessionless in SessionMiddleware
- Bring touched files to PHPStan level 9 compliance
- Clear remaining PHPStan level 8 errors, bring touched files to level 9
- Don't run code coverage when releasing

### 💼 Other

- :removeError doesn't return by reference anymore, closes #110
- :isReadonly()
- Cleaned up, added support for per-env and per-context <configuration> blocks (which is pretty limited though for obvious reasons)
- :NONE is back in town
- :validate now gets request parameters as an AgaviParameterHolder.
- Removed superfluous indent and made member declarations compatible with PHP guidelines
- Runtime configurability of all settings, control over DOM options, support for initial population of multiple forms (ATTN, BREAKING CHANGE: array keys are form id, value a ParameterHolder of data to populate. use ParameterHolders now to populate the form that belongs to the action. new AgaviParameterHolder(array(...)) does the job)), parse XHTML as XML (setting 'parse_xhtml_as_xml', default true). refs #327 (points 1, 2, 4 and 7). CURRENTLY SEGFAULTING OR THROWING RANDOM ERRORS, SHOWING PERFORMANCE ISSUES ON PHP 5.0.4, POSSIBLY OTHER VERSIONS, WORKING ON A FIX
- Implemented remaining items 3 and 6: support for skipping re-population of certain fields and full support for [] fields (i.e. fields with auto-generated indices). closes #327
- :getAvailableLocales()
- Fixed population of multi-selects and fixed a situation where a fatal error could occur when populating multiple forms
- Caching. one config file per action, definitions can be specific to one or more request-method, each definition can contain settings specific to one or more output types, groups (like in smarty, multiple sources like string, locale, request param etc), cache TTL ('2 days 4 hours'), caching can be controlled on a per layer level, slots can be included in the cache, action attribs, template vars and request attribs (yes, with namespace) can be restored, restrictable to certain views, closes #78. also did some minor fixes here and there, added slots to sample app.
- :mkdir() mode defaults to 0775, UploadedFile::move() modes default to 0775 and 0664. fixes #402
- Keywords=Id
- :gen() options presets support, see the ticket for instructions, but use 'gen_options_presets' (not 'options_presets' as in the ticket description), closes #432
- Earthli is a lot nicer
- :createExecutionContainer() now copies over container parameters, closes #699
- :writeCache() now uses third argument with lifetime, closes #702
- :create*Container() should use null as default for arguments, closes #735
- :createForwardContainer() now carries over the arguments from the current container if no arguments were given as the third parameter. If arguments are given, those are used exclusively; no merging with the current container's arguments happens (to do that, grab the current container's arguments object and merge() by hand). Closes #707
- :dispatch() now accepts module and action names in the request data argument, partially already implemented in d422ee37f722fb8bed7cae4b97aeabeae4630755 for #777, but now all is fine and consistent. Still doesn't allow overwriting of module and action parameters with routing enabled, mind you. Closes #776 and closes #777
- Bugfixes and add view-create, refs #689
- Preg_quote context and environment names
- Set the memory limit for the build system to 2^32-1 bytes instead of 2^32 bytes so that PHP will actually start up
- Fix #805: System actions' templates are always copied to the first module
- :createRequestDataHolder() now uses by default the RequestDataHolder-Class defined for the given context/request
- Small fix for AgaviXmlConfigSchematronProcessor, refs #844
- Project configuration system: Whitespace fixes
- :gen() Tests with unescaped parameters etc
- :render() should send shell exit code, closes #990
- Disable logging by default in sample app, refs #998
- Ignore for cache dir
- :dispatch() should accept an AgaviExecutionContainer as optional second argument, refs #1012
- :createCustomTimeZone() now throw exceptions for unparseable TZ strings, closes #958
- Keywords Id
- Keywords Id
- Keywords Id
- Keywords Id
- :assertNotHasLayer(), closes #1278
- AgaviUploadedFile  to detect and handle stream data  correctly
- Hot-path wins + Symfony-style warmup/compile stage

### 🚜 Refactor

- [**breaking**] Decompose WebRequest into immutable collaborators
- [**breaking**] Resolve plugin names from #[Plugin] attribute, not PluginInterface::name()
- Drop dead CLDR accessors from QuioteLocale, match locales via intl

### 📚 Documentation

- Mention authentication in the v1.0.0 release notes
- Drop Jakamo mentions from auth-related comments

### 🧪 Testing

- Raise unit test coverage across validator, translation, config, and view internals

### ⚙️ Miscellaneous Tasks

- Add coverage reporting and tag-triggered release automation
- Stop tracking docs/ and TODO.md
- Ignore .phpunit.cache
- Hand-craft v1.0.0 release notes
- Remove remaining Jakamo references from tracked source
- Ignore editor and AI agent config files
