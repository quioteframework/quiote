## [4.0.0-RC1] - 2026-08-19

### 🚀 Features

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
- *(replay)* Add a cassette-index chain to resolve a bare id to a cassette
- *(replay)* Build isolated replay mode, and make it the default

### 🐛 Bug Fixes

- *(replay)* Make meta.effects_instrumented a real cassette field
- *(replay)* Escape cassette text interpolated into emitted test comments
- *(replay)* Redact response headers so Set-Cookie never enters a cassette
- *(replay)* Bound cassette inflation against a decompression bomb
- *(replay)* Cut truncated bodies and masked values on character boundaries
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

### 🚜 Refactor

- *(replay)* Extract cassette projection and test emission

### 📚 Documentation

- *(replay)* Remove internal plan-doc citations from code comments
- *(replay)* Add changelogs for the eight replay packages
- *(replay)* Make the replay packages 4.0.0-RC1, not 4.0.0
