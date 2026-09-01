## [4.0.0] - 2026-08-26

### 🚀 Features

- *(replay)* Wire DB effects into live requests via a generic EffectSource seam
- *(replay)* Wire Doctrine/Eloquent/Cycle DB effects into live requests
- *(replay)* Add PDO cassette store and cassette:prune
- *(replay)* Build isolated replay mode, and make it the default

### 🐛 Bug Fixes

- *(replay-doctrine)* Only snapshot a result set when a ledger is actually active
- *(replay)* Give a db effect's result one shape, and make the adapters compose

### 📚 Documentation

- *(replay)* Add changelogs for the eight replay packages
- *(replay)* Make the replay packages 4.0.0-RC1, not 4.0.0
