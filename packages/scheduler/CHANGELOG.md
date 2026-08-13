## [4.0.0] - 2026-08-11

### 🚀 Features

- *(scheduler)* Add cron-expression schedule:run command

### 🐛 Bug Fixes

- *(scheduler)* Rebind the default schedule per test to stop cross-test leakage
- *(packages)* Narrow mixed types across plugin packages and samples/app for PHPStan level 9
- *(packages)* [**breaking**] Require the framework by version, not by "*"
- *(di)* [**breaking**] An omitted scope means what the binding declares, not process lifetime
- *(analysis)* Clear the last 60 PHPStan level 9 errors project-wide

### 💼 Other

- *(composer)* Alias dev-main to 4.0.x-dev across the monorepo

### 📚 Documentation

- *(api)* Document every public method and class across the framework
