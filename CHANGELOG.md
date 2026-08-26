## [4.3.0-RC1] - 2026-08-26

### 🚀 Features

- *(replay)* Let replay override the request URI and impersonate a live session

### 🐛 Bug Fixes

- *(replay)* Capture the exception and log entries a recorded request actually produced
## [4.2.0] - 2026-08-26

### 🚀 Features

- *(routing)* Diagnose the views an action returns, not just the one it declares

### 🐛 Bug Fixes

- *(ci)* Scope package release notes by range, not by --current

### 📚 Documentation

- Prep v4.2.0

### ⚙️ Miscellaneous Tasks

- *(release)* Bump the framework version to 4.2.0
## [4.2.0-RC2] - 2026-08-26

### 🚀 Features

- *(config)* Resolve %env(NAME)% placeholders in compiled config at load time
- *(config)* Add Rule::oneOf union schema type
- *(config)* Let a plugin's enabled setting defer to the environment

### 🐛 Bug Fixes

- *(ci)* Scope the framework release notes by range, not by --current
- *(support)* Make SystemEnvironmentReader see $_ENV, not just getenv()
- *(runtime)* Read framework env vars through the environment seam

### 📚 Documentation

- *(upgrading)* Correct the RC install recipes against the published versions
## [4.2.0-RC1] - 2026-08-20

### 🚀 Features

- *(storage)* Add Azure AD credentials and cross-provider object listing
- *(clock)* Add a Quiote\Support\Clock seam and convert every ambient now() read
- *(random)* Add a Quiote\Support\Random seam and convert every ambient random_bytes/random_int read
- *(replay)* Add quioteframework/replay with the effect-ledger primitives
- *(replay)* Add a decorating PDO recorder and isolated-replay stub
- *(replay)* Add a Propulsion query observer for the effect ledger
- *(replay)* Add an HTTP client recording transport and isolated-replay stub
- *(replay)* Add a cache recording decorator and isolated-replay stub
- *(replay)* Add a queue-push recording decorator and an assert-only replay driver
- *(env)* Add a Quiote\Support\Environment seam and a replay recorder/stub
- *(replay)* Add a Doctrine DBAL query recorder for the effect ledger
- *(replay)* Add an Eloquent query recorder for the effect ledger
- *(replay)* Add a Cycle query recorder for the effect ledger
- *(replay)* Add cassette format, recorder middleware, and console commands
- *(replay)* Wire DB effects into live requests via a generic EffectSource seam
- *(replay)* Wire Doctrine/Eloquent/Cycle DB effects into live requests
- *(replay)* Add --as-test/ReplayTestCase test emission from a cassette
- *(replay)* Add PDO cassette store and cassette:prune
- *(replay)* Add an object-store-backed cassette store for Azure Blob
- *(replay)* Add a cassette-index chain to resolve a bare id to a cassette
- *(replay)* Build isolated replay mode, and make it the default
- *(replay)* Isolate Propulsion by substituting the connection

### 🐛 Bug Fixes

- *(execution)* Give slot-rendered views the live ValidationManager
- *(storage)* Require quioteframework/storage from the framework itself
- *(plugin)* Close plugin-state cleanup gaps found auditing packages/*
- *(test)* Distinguish a stored null from a miss in Psr16KeyRecordingCache
- *(replay)* Make meta.effects_instrumented a real cassette field
- *(replay)* Escape cassette text interpolated into emitted test comments
- *(replay)* Redact response headers so Set-Cookie never enters a cassette
- *(replay)* Bound cassette inflation against a decompression bomb
- *(replay)* Cut truncated bodies and masked values on character boundaries
- *(replay-doctrine)* Only snapshot a result set when a ledger is actually active
- *(replay)* Refuse a positional ledger match instead of answering with another call's result
- *(replay)* Bound effect payloads by bytes and report every truncation in meta
- *(replay)* Redact the effect ledger at the one point every recorder shares
- *(replay)* Gate live replay on safe methods, and gate emitted tests too
- *(replay)* Close the three remaining secret-leak paths in recording
- *(replay)* Make the PDO recorder faithful to the connection it decorates
- *(replay)* Give a db effect's result one shape, and make the adapters compose
- *(replay)* One timestamp rule, resilient index chain, and far fewer store round trips
- *(replay)* Anchor the store path, honour the PSR contracts, and stop paying for discarded work
- *(replay)* Treat a cassette as untrusted input on the replay path
- *(replay)* Select the cassette store by config, not by plugin load order
- *(storage)* Read ETags and oversized lengths correctly, and test the package

### 🚜 Refactor

- *(storage)* Decouple cloud-azure/cloud-s3/cloud-gcs from the framework
- *(filesystem)* Extract Quiote\Filesystem\* into its own package
- *(replay)* Extract cassette projection and test emission

### 📚 Documentation

- *(replay)* Remove internal plan-doc citations from code comments
- *(replay)* Add changelogs for the eight replay packages
- *(packages)* Add the three missing package changelogs
- *(replay)* Make the replay packages 4.0.0-RC1, not 4.0.0
- *(release)* Track the pending v4.2.0-RC1 release plan and its open questions
- *(replay)* Fold isolated mode into the changelogs and the release plan
- *(release)* Correct the replay RC rationale now that isolated mode has landed
- *(replay)* Record the Propulsion isolation in the changelogs
- Add UPGRADING.md and make cloud-azure an RC
- Prep v4.2.0-RC1
- *(storage)* Record the ObjectMetadata fixes in the changelog

### 🧪 Testing

- *(config)* Document environment-dependent value patterns for PHP-array databases files

### ⚙️ Miscellaneous Tasks

- *(ci)* Add new replay packages to package split
## [4.1.0] - 2026-08-14

### 🚀 Features

- *(release)* Version packages independently of the framework from v4.0.0
- *(exception)* Add pluggable safe/production exception renderer slot

### 🐛 Bug Fixes

- *(db-propulsion)* Allow Propulsion 3.x alongside 2.x
- *(validator)* Default-export sanitized/decoded value under own argument name

### 🚜 Refactor

- *(view)* Replace TemplateLayer's magic accessors with typed methods
## [4.0.0] - 2026-08-11

### 🐛 Bug Fixes

- *(db-propulsion)* Resolve the connection fresh on every getConnection()/getResource() call
- *(db-eloquent)* Follow the referenced database when its handle rotates in layer mode
- Bump quiote version to 4.0.0
## [4.0.0-RC6] - 2026-08-10

### 🐛 Bug Fixes

- *(logging)* Re-resolve category loggers when the configuration changes
- *(util)* Return the matching method from Toolkit::overloadHelper()
- *(db)* Make reset() honour the Database teardown contract
- *(testing)* Make ViewTestCase's assertions compare what they document
- *(db-propulsion)* Stop discarding live connections on every initialize()

### 🚜 Refactor

- *(validation)* [**breaking**] Rename xmlOnlyValidate() to validateDeclaredOnly()
- *(exception)* [**breaking**] Drop QuioteException's exception-page helpers
- *(i18n)* Drop DateTimeFacade's unreachable non-intl fallbacks
- *(execution)* [**breaking**] Remove ViewResolver and ActionExecutionSession
- *(validator)* [**breaking**] Remove four uncalled deprecated methods
- *(util)* Decompose FormPopulationEngine into its responsibilities
- *(execution)* Extract slot parameter overlay and caching

### 📚 Documentation

- *(renderer)* Describe what PhpRenderer actually gives a template
- *(migrating)* Record the 4.0 removals and the validation rename
- Prep v4.0.0-RC6

### 🧪 Testing

- *(session,queue)* Cover the Redis and object-store backends without Docker
- *(db)* Cover the adapter parameter mapping and worker lifecycle
- *(runtime)* Cover Kernel's runtime selection and the error backstop
- *(execution)* Cover slot dispatch, deferred slots and validation diagnostics
- *(testing,renderer)* Cover the test-support toolkit and the PHP renderer
- *(user)* Cover what SecurityUser does when the session backend fails
- *(util,validator)* Cover the worker manager, silencer and validators
- *(util)* Pin form population's behaviour at the document level
- *(util)* Cover the XHTML repairs and form matching directly
## [4.0.0-RC5] - 2026-08-09

### 🚀 Features

- *(middleware)* Add core.stealth_mode to hide framework-identifying headers
- *(docs)* Add the API reference generator package with source discovery
- *(docs)* Generate the API reference from reflection and docblocks
- *(middleware)* Clear resettable middleware state at the request boundary

### 🐛 Bug Fixes

- *(config)* A factory being switched off is not the same as being optional
- *(session)* Read a Postgres bytea session blob as a stream, not a string
- *(routing)* Stop RoutingValue::reset() unsetting a shared static property
- *(execution)* Restore slot parameters from the validated request
- *(analysis)* Clear the last 60 PHPStan level 9 errors project-wide
- *(middleware)* Drop the _original_psr_request attribute
- *(docs)* Stop the root namespace listing itself as a section
- *(auth)* Scope the token-derived marker to the request that presented the token
- *(user)* Rotate the session id when a token identity becomes a session login

### 📚 Documentation

- *(api)* Document every public method and class across the framework
- *(session)* Drop the token-derived marker from the exists() rationale
## [4.0.0-RC4] - 2026-08-07

### 🚀 Features

- *(translation)* QuioteLocale answers its own text direction again
- *(translation)* QuioteLocale names a currency in its own locale

### 🐛 Bug Fixes

- *(rector)* [**breaking**] Close the residue reporter's two blind spots
- *(di)* [**breaking**] An omitted scope means what the binding declares, not process lifetime
- *(validator)* Build declared validators through the container, propagate exports
- *(validator)* Carry a validator's synthetic name into its own parameters
- *(auth)* Carry a stateless passport's validated claims onto SecurityUser
## [4.0.0-RC3] - 2026-08-05

### 🐛 Bug Fixes

- *(testing)* Clear the shared models through the locator
- *(composer)* [**breaking**] Require the CSRF package by version, not by stability alone
- *(renderer)* [**breaking**] Resolve a snake_case assign against the camelCase container role

### 📚 Documentation

- *(migrating)* Note that output_types assigns resolve through the container

### ⚙️ Miscellaneous Tasks

- *(release)* Prepare 4.0.0-RC3
## [4.0.0-RC2] - 2026-08-05

### 🐛 Bug Fixes

- *(packages)* [**breaking**] Require the framework by version, not by "*"

### ⚙️ Miscellaneous Tasks

- *(release)* Regenerate the 4.0.0-RC1 changelog section
- *(release)* Prepare 4.0.0-RC2
## [4.0.0-RC1] - 2026-08-05

### 🚀 Features

- *(mcp)* Wire OAuth2 resource-server auth into the MCP HTTP endpoint
- *(di)* Introduce contracts for the four core seams
- *(validator)* Let validators declare constructor dependencies
- *(rector)* Start the Context-decomposition rule set with rule 1 and its type-resolution foundation
- *(rector)* Add rule 2, and the two guards the framework dry-run proved necessary
- *(rector)* Rule 3's request half, written and then withheld as unsound
- *(rector)* Rule 3's request half, corrected to target RequestState
- *(rector)* Add rule 4, getModel() to an injected ModelLocator
- *(rector)* Add rule 5, Context::getInstance() to an injected ContextRegistry
- *(rector)* Add the residue reporter, unregistered pending a static-call gap
- *(config)* [**breaking**] Config handlers return declarations that the framework applies
- *(validator)* [**breaking**] Compile validators.xml to a declaration instead of registration statements
- *(rector)* Rewrite Context::getUser() to an injected user
- *(context)* [**breaking**] Delete Context::handle() in favour of the PSR-15 handler
- *(context)* [**breaking**] Bind the optional components so their absence explains itself

### 🐛 Bug Fixes

- *(console)* Stop make:* leaking raw warnings before its own error
- *(translation)* Restore the default locale on reset()
- *(config)* Create the cache directory tree when writing a compiled config
- *(plugin)* Clear middleware contributions in PluginManager::reset()
- *(core)* Narrow mixed types across Quiote/ and tests/ for PHPStan level 9
- *(packages)* Narrow mixed types across plugin packages and samples/app for PHPStan level 9
- *(cache,translation)* Scope cache keys by locale, fix bypass and reset() bleed
- *(mcp)* Stop dropping additionalProperties in tool schemas
- *(security)* Close login CSRF, host-header poisoning and auth timing gaps
- *(cors)* [**breaking**] Refuse a wildcard origin combined with credentials
- *(security)* Harden cache dir, session expiry, proxy TLS detection and XSLT
- *(validator)* Scrub a failed parameter whatever source it came from
- *(cache)* [**breaking**] Bind a secure action's cached output to one identity
- *(cache,runtime)* Clear per-request worker state at the request boundary
- *(user)* Keep the surviving roles' permissions when revoking a role
- *(cache,session,response)* Close conformance and consistency gaps
- *(di)* [**breaking**] Stop a singleton capturing request-scoped state
- *(cache)* Stop an evicted namespace version resurrecting retired entries
- *(runtime,context)* Stop swallowing failures on the worker request path
- *(response)* [**breaking**] Accept the full HTTP status range instead of a code whitelist
- *(view)* Make the attribute facade answer from one coherent store
- *(request)* [**breaking**] Keep the URL metadata and the wrapped PSR-7 URI in sync
- *(http)* [**breaking**] Make PsrResponseAdapter a real immutable PSR-7 response
- *(di)* [**breaking**] Bind core services under their base class, not only the concrete one
- *(config)* Key the config cache on the framework build, not only on source mtimes
- *(rector)* Merge the residue report across Rector workers, and register rule 6
- *(rector)* Stop re-promoting a constructor property the parent owns
- *(composer)* [**breaking**] Make the Rector migration rules a dev dependency
- *(di)* [**breaking**] Default a bare #[Service] to transient scope
- *(user)* Read RBAC definitions through the active config cache
- *(rector)* Recognise a Context reached through a nullable getContext()
- *(rector)* Never add a constructor to a class other classes extend
- *(renderer)* Call the assign resolvers in the Twig and PHPTAL renderers
- *(rector)* Stop reporting the methods Context still declares as residue

### 💼 Other

- *(composer)* Alias dev-main to 4.0.x-dev across the monorepo

### 🚜 Refactor

- *(execution)* [**breaking**] Remove the ExecutionContainer cluster
- *(response)* [**breaking**] Fold Response into WebResponse, drop ConsoleResponse
- *(response)* [**breaking**] Emit through the runtime emitter instead of the SAPI
- *(request,diagnostics)* Drop two unreachable classes
- *(context)* Rebuild lazy core components through one code path
- *(middleware)* [**breaking**] Decompose ValidationMiddleware::process()
- *(view,action,response)* Extract the shared attribute facade and split response concerns
- *(config)* [**breaking**] Back the Config facade with an injectable repository
- *(logging,middleware)* Stop swallowing failures silently on the dispatch path
- *(middleware)* Declare the shipped middleware once
- *(logging)* Finish the empty-catch sweep across the framework
- *(packages)* Extend the empty-catch sweep to the first-party packages
- *(session)* [**breaking**] Serialize session payloads through one codec
- *(filesystem)* [**breaking**] Segregate listing from the filesystem contract
- *(storage)* [**breaking**] Give the object stores one contract and one implementation
- *(telemetry)* Split TelemetryBootstrap into config, exporters and providers
- *(model)* Give model resolution and model lifetimes their own classes
- *(context)* Give the per-profile instance registry its own class
- *(context)* [**breaking**] Give the shutdown sequence its own class
- *(context)* Make the request-boundary clears a declared, guarded sequence
- *(config)* [**breaking**] Compile the factories configuration to data, not $this-mutating code
- *(database)* [**breaking**] Compile the databases configuration to data, not $this-mutating code
- *(controller)* [**breaking**] Compile the output_types configuration to data, not $this-mutating code
- *(runtime)* [**breaking**] Give request handling its own class, and scope the execution helpers
- *(context)* Rename RequestBoundaryCleanup to ContextLifecycle and give it the whole request state machine
- *(translation)* [**breaking**] Compile the translation configuration to data, not $this-mutating code
- *(config)* [**breaking**] Serialize a compiled configuration in the cache, not in the handler
- *(context)* [**breaking**] Narrow ContextInterface to the profile and its container
- *(context)* [**breaking**] Take seven accessors off Context, and let the container carry the types
- *(context)* [**breaking**] Make the on-demand slots container bindings, not a factory-info bag
- *(context)* [**breaking**] Bind the session manager and bag instead of accessing them off Context
- *(context)* [**breaking**] Reach the translation and database managers through the container
- *(context)* [**breaking**] Bind the routing and the controller as lazy factories
- *(context)* [**breaking**] Bind the user, and let CurrentUser resolve it
- *(context)* [**breaking**] RequestState owns the request, and Context stops accessing it
- *(context)* [**breaking**] Make the context's state private and its docblocks current

### 📚 Documentation

- *(migrating)* Document the 3.2 breaking changes
- *(migrating)* Document the package-level 3.2 breaking changes
- *(rector)* Correct the rule-set config's installation and coverage notes
- *(config)* State that loadValue() is only for configs that return data

### ⚡ Performance

- *(config)* [**breaking**] Serve a compiled config's value from shared memory instead of recompiling it

### 🧪 Testing

- *(csrf)* Run the CSRF suite against a real session manager
- *(csrf)* State the CSRF guarantee as an adversary table
- *(middleware)* Assert the guarded set and the ordering relations directly
- *(worker)* Assert request-boundary isolation with faults injected
- Cover the Action attribute facade, response lifecycle and CLI scaffolding
- Stop tests leaking global state into each other
- *(middleware)* Stop a leftover preinstantiated action hijacking a dispatch
- *(db-propulsion)* Stop naming a connection class the adapter already picks
- *(benchmarks)* Measure registering validators from a declaration
- *(rector)* Name the sites the rules skip, and cover the reporter itself

### ⚙️ Miscellaneous Tasks

- Raise PHPStan baseline to level 9 across the repo
- *(rector)* Register the package for the subtree split, and stop implying it is published
- *(release)* Prepare 4.0.0-RC1
## [3.1.0] - 2026-07-29

### 🐛 Bug Fixes

- *(ci)* Bump action-gh-release to v3 for Node 24 runtime
- *(csrf)* Validate against the real session cookie and a proven credential
- *(worker)* Never let a failed context reset leak the previous user
- *(security)* Fail closed when an action's security cannot be evaluated
- *(session)* Delete the old id outright on a privilege transition
- *(console)* Scaffold a session slot so a new app actually enforces CSRF
- *(middleware)* Extend the framework-override guard to the CSRF middleware
- *(middleware)* Refuse to drop a framework middleware's ordering constraint
- *(auth)* Close the login enumeration oracle and throttle per client
- *(auth)* Reject unusable firewall patterns and match the normalized path
- *(queue)* Verify the job class before constructing it
- *(cors)* Never emit a wildcard origin alongside credentials
- *(ratelimit)* Read the trusted end of X-Forwarded-For, not the client's
- *(mcp)* Fail a tool call that was forwarded instead of returning the login page
- *(auth)* Parse the Authorization scheme case-insensitively
- *(auth-jwt)* Claim a bare Bearer header and parse the scheme per RFC 9110
- *(middleware)* Negotiate the validation-failure representation

### 📚 Documentation

- *(auth-oauth)* State the real single-use guarantee for OIDC state
## [3.0.2] - 2026-07-29

### 🚀 Features

- *(console)* Generate make:action templates from the configured renderer

### 🐛 Bug Fixes

- Repair four latent defects in headers, cache keys, OAuth scopes and rate limiting
## [3.0.1] - 2026-07-29

### 🚀 Features

- *(filesystem)* Read cloud file metadata over HEAD

### ⚙️ Miscellaneous Tasks

- *(packages)* Credit the cloud-* packages in filesystem-* metadata
## [3.0.0] - 2026-07-29

### 🚀 Features

- *(session)* Add SessionBagInterface as the single session seam
- *(session)* [**breaking**] Make the PSR-7 session stack selectable, and harden it
- *(middleware)* Select the PSR-7 session stack when the slot is configured
- *(session)* [**breaking**] Remove the ext/session storage stack
- *(session)* Ship a slot factory for every session backend

### 🐛 Bug Fixes

- *(storage)* Release the read cursor in the PDO session backends
- *(session)* Release the load cursor and make the PDO upsert portable
- *(storage)* [**breaking**] Repair the native session lifecycle under worker runtimes
- *(core)* [**breaking**] Persist request state before the session is closed
- *(user)* [**breaking**] Only write session state that actually changed

### 🚜 Refactor

- *(middleware)* Resolve session ids through the bag, and narrow types
- *(packages)* [**breaking**] Extract the cloud clients into cloud-* packages

### 📚 Documentation

- Add MIGRATING.md, and a PDO factory for the session slot
- Target MIGRATING.md at a single 3.0 release

### 🧪 Testing

- *(runtime)* Prove session identity survives across worker requests
- *(runtime)* [**breaking**] Cover FrankenPHP in the worker integration suite

### ⚙️ Miscellaneous Tasks

- Add scheduler to the subtree splitter
- Keep DOCS_TODO.md contributor-local
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
