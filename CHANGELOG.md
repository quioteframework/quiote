## [4.4.1-RC1] - 2026-09-02

### 🐛 Bug Fixes

- *(testing)* Stop ActionTestCase swallowing validator config errors
- *(validator)* Check unknown parameters on ValidatorBuilder::raw() too
- *(release)* Stop package-only commits leaking into the framework changelog
- *(middleware)* Re-fetch the canonical request before manual validate hooks

## [4.4.0] - 2026-09-01

### 🚀 Features

- *(exception-notifier)* Add exception notification plugin with Teams and webhook channels
- *(console)* Add plugins:list and middleware:list commands
- *(console)* Show a plugin's declaration source in plugins:list

### 🐛 Bug Fixes

- *(tests)* Stop PdoSessionPersistencePostgresTest hanging when Postgres is unreachable
- *(http)* Stop corrupting a signed URL when posting to a base URI verbatim
- *(ci)* Quote --ignore-tags' value so clap doesn't mistake it for a flag

### 📚 Documentation

- Add exception-notifier changelog and update the root changelog
- *(changelog)* Adopt stable-only changelog entries, clean up RC noise
- *(changelog)* Collapse the framework's historical RC entries into their GA
- *(changelog)* Stop duplicating CHANGELOG-1.2's history in CHANGELOG.md

### ⚙️ Miscellaneous Tasks

- Fix dev-main branch-alias for every package with a release
- *(release)* Prepare 4.4.0-RC1
- *(release)* Prepare 4.4.0-RC2
- *(release)* Scope generated changelogs from the last stable tag, not the last tag
## [4.3.0] - 2026-08-27

### 🚀 Features

- *(replay)* Let replay override the request URI and impersonate a live session
- *(replay)* Let replay override query string and body params too
- *(replay)* Disable CSRF validation during replay by default

### 🐛 Bug Fixes

- *(replay)* Capture the exception and log entries a recorded request actually produced
- *(replay)* Capture parsed body fields for multipart/form-data requests
- *(core)* Stop the caught-exception publish from discarding routing state

### 📚 Documentation

- Fold the query/body override change into the v4.3.0-RC1 changelogs
- Prep v4.3.0

### ⚙️ Miscellaneous Tasks

- *(release)* Prepare 4.3.0-RC1
- *(release)* Prepare 4.3.0-RC2
- *(release)* Bump the framework version to 4.3.0
## [4.2.0] - 2026-08-26

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
- *(config)* Resolve %env(NAME)% placeholders in compiled config at load time
- *(config)* Add Rule::oneOf union schema type
- *(config)* Let a plugin's enabled setting defer to the environment
- *(routing)* Diagnose the views an action returns, not just the one it declares

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
- *(ci)* Scope the framework release notes by range, not by --current
- *(support)* Make SystemEnvironmentReader see $_ENV, not just getenv()
- *(runtime)* Read framework env vars through the environment seam
- *(ci)* Scope package release notes by range, not by --current

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
- Refresh the v4.2.0-RC1 changelog for the storage fixes
- *(release)* Split the cloud-azure and db-doctrine baselines
- *(upgrading)* Correct the RC install recipes against the published versions
- Prep v4.2.0-RC2
- Prep v4.2.0
- Fold the triad scanner change into the v4.2.0 changelog

### 🧪 Testing

- *(config)* Document environment-dependent value patterns for PHP-array databases files

### ⚙️ Miscellaneous Tasks

- *(ci)* Add new replay packages to package split
- *(release)* Bump the framework version to 4.2.0
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
- *(translation)* QuioteLocale answers its own text direction again
- *(translation)* QuioteLocale names a currency in its own locale
- *(middleware)* Add core.stealth_mode to hide framework-identifying headers
- *(docs)* Add the API reference generator package with source discovery
- *(docs)* Generate the API reference from reflection and docblocks
- *(middleware)* Clear resettable middleware state at the request boundary

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
- *(packages)* [**breaking**] Require the framework by version, not by "*"
- *(testing)* Clear the shared models through the locator
- *(composer)* [**breaking**] Require the CSRF package by version, not by stability alone
- *(renderer)* [**breaking**] Resolve a snake_case assign against the camelCase container role
- *(rector)* [**breaking**] Close the residue reporter's two blind spots
- *(di)* [**breaking**] An omitted scope means what the binding declares, not process lifetime
- *(validator)* Build declared validators through the container, propagate exports
- *(validator)* Carry a validator's synthetic name into its own parameters
- *(auth)* Carry a stateless passport's validated claims onto SecurityUser
- *(config)* A factory being switched off is not the same as being optional
- *(session)* Read a Postgres bytea session blob as a stream, not a string
- *(routing)* Stop RoutingValue::reset() unsetting a shared static property
- *(execution)* Restore slot parameters from the validated request
- *(analysis)* Clear the last 60 PHPStan level 9 errors project-wide
- *(middleware)* Drop the _original_psr_request attribute
- *(docs)* Stop the root namespace listing itself as a section
- *(auth)* Scope the token-derived marker to the request that presented the token
- *(user)* Rotate the session id when a token identity becomes a session login
- *(logging)* Re-resolve category loggers when the configuration changes
- *(util)* Return the matching method from Toolkit::overloadHelper()
- *(db)* Make reset() honour the Database teardown contract
- *(testing)* Make ViewTestCase's assertions compare what they document
- *(db-propulsion)* Stop discarding live connections on every initialize()
- *(db-propulsion)* Resolve the connection fresh on every getConnection()/getResource() call
- *(db-eloquent)* Follow the referenced database when its handle rotates in layer mode
- Bump quiote version to 4.0.0

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
- *(validation)* [**breaking**] Rename xmlOnlyValidate() to validateDeclaredOnly()
- *(exception)* [**breaking**] Drop QuioteException's exception-page helpers
- *(i18n)* Drop DateTimeFacade's unreachable non-intl fallbacks
- *(execution)* [**breaking**] Remove ViewResolver and ActionExecutionSession
- *(validator)* [**breaking**] Remove four uncalled deprecated methods
- *(util)* Decompose FormPopulationEngine into its responsibilities
- *(execution)* Extract slot parameter overlay and caching

### 📚 Documentation

- *(migrating)* Document the 3.2 breaking changes
- *(migrating)* Document the package-level 3.2 breaking changes
- *(rector)* Correct the rule-set config's installation and coverage notes
- *(config)* State that loadValue() is only for configs that return data
- *(migrating)* Note that output_types assigns resolve through the container
- *(api)* Document every public method and class across the framework
- *(session)* Drop the token-derived marker from the exists() rationale
- *(renderer)* Describe what PhpRenderer actually gives a template
- *(migrating)* Record the 4.0 removals and the validation rename
- Prep v4.0.0-RC6

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
- *(session,queue)* Cover the Redis and object-store backends without Docker
- *(db)* Cover the adapter parameter mapping and worker lifecycle
- *(runtime)* Cover Kernel's runtime selection and the error backstop
- *(execution)* Cover slot dispatch, deferred slots and validation diagnostics
- *(testing,renderer)* Cover the test-support toolkit and the PHP renderer
- *(user)* Cover what SecurityUser does when the session backend fails
- *(util,validator)* Cover the worker manager, silencer and validators
- *(util)* Pin form population's behaviour at the document level
- *(util)* Cover the XHTML repairs and form matching directly

### ⚙️ Miscellaneous Tasks

- Raise PHPStan baseline to level 9 across the repo
- *(rector)* Register the package for the subtree split, and stop implying it is published
- *(release)* Prepare 4.0.0-RC1
- *(release)* Regenerate the 4.0.0-RC1 changelog section
- *(release)* Prepare 4.0.0-RC2
- *(release)* Prepare 4.0.0-RC3
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

For 1.2.4 and earlier -- including the last Agavi commit through the 1.2.0
rename to Quiote -- see [CHANGELOG-1.2](CHANGELOG-1.2).
