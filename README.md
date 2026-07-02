# Quiote

- License: LGPL-2.1
- Latest Version: 1.0.0-pre-alpha1
- Build: N/A
- Homepage: [https://github.com/quioteframework/quiote/](https://github.com/quioteframework/quiote/)

## History

Quiote is a fork/rewrite of Agavi, an MVC framework that started life around 2006 and was maintained until the mid 2010s. Agavi was a fork from an older MVC framework called Mojavi. Mojave desert -> Mojavi. The agave plant grows in the Mojave desert -> Agavi. Agavi being an excellent framework continued serving well for around a decade after active development stopped, but in 2025 the (probably) main company using Agavi decided it needed a refresh and started porting it to PHP 8. 

A lot of things had changed during the 20 years since Agavi's inception. There were no middleware, no DI containers, no PSR-7/PSR-15. While the project started as a direct port to PHP 8.4, it became painfully obvious that a lot of things needed to change in the internals, and as work progressed the fork strayed further and further away from the original Agavi design.

- **PSR-15 middleware pipeline** replaces Agavi's global/action filter chain end to end (routing, content negotiation, CSRF, security, validation, dispatch, form population, and more each live in their own middleware).
- **PSR-7 HTTP messages** (via `nyholm/psr7`) instead of Agavi's bespoke request/response objects.
- **A DI container** (`Quiote\DI\Container`) with constructor injection for actions, services, and views, replacing `factories.xml`-driven instantiation.
- **PSR-3-compatible structured logging** (`Quiote\Logging\*`), with per-category log levels and pluggable sinks, replacing ad-hoc error logging.
- **CSRF protection** built into the middleware stack (`symfony/security-csrf`), covering both cookie-based PHP UIs and header-based API/SPA clients.
- **A config system that isn't XML-only anymore**: `settings`, `factories`, `databases` and most other config types can be written as plain PHP arrays or YAML instead of XML, mixed and matched per file, with autodetection or an explicit `core.config_format` override, and full `parent`/`imports` inheritance across formats.
- **A validator compiler**: XML `validators.xml` files still work, but validators can now be declared directly in PHP via a fluent builder
- **Symfony components** for routing, caching (including APCu-backed config caching for persistent workers like FrankenPHP), rate limiting, and YAML parsing, instead of Agavi's homegrown equivalents.
- **A modern PHP 8.5 codebase**: typed properties, enums, readonly properties, first-class callable syntax, and attributes throughout, in place of the PHP 5-era code Agavi started with.

Quiote is the flower that blooms from the agave plant at the end of it's life.

## Purpose

Quiote is a *powerful, scalable PHP 8.5 application framework* that follows the MVC
paradigm. It enables developers to write clean, maintainable and extensible
code. Quiote puts choice and freedom over limiting conventions, and focuses on
sustained quality rather than short-sighted decisions.

Quiote is designed for serious development. It is not a complete website
construction kit but rather a skeleton over which you build your application.
The architecture of Quiote allows developers to retain very fine control over
their code.

Quiote strives to leave most implementational choices to the developers. Quiote's
components are inherently extensible, and the framework itself is designed
around a configuration system that provides a very flexible environment.

The framework works for almost all kinds of applications but excels most in
large codebases, long-term projects, extreme cases of integration and other
special situations.

## Requirements and installation

- PHP 8.5
- required: `libxml`, `dom`, `SPL`, `Reflection` and `PCRE`
- optional: `xsl`, `tokenizer`, `session`, `xmlrpc`,  `PDO`, `iconv`, `gettext`

No releases or packagist packages yet, as we are pre-alpha.

Alternatively, you can download a release archive from the [github releases]
page and extract it or see the [downloads page] on the homepage.

## Documentation

TBD

## Contribution

Discussing issues in github issues as well as talking is always of good help to
everyone. If you want to do more please contribute by [forking](https://help.github.com/forking/)
and sending a [pull request](https://help.github.com/pull-requests/). More
information can be found in the [CONTRIBUTING.md](CONTRIBUTING.md) file.


## License

Quiote is licensed under the <a rel="license" href="https://en.wikipedia.org/wiki/GNU_Lesser_General_Public_License">LGPL 2.1</a>.
See the [Open Source Initiative](http://opensource.org/licenses/LGPL-2.1)
and [this FAQ entry](https://github.com/quiote/quiote/wiki/FAQ#wiki-can-i-use-quiote-in-a-proprietary-commercial-application)
for details. All relevant licenses and details can be found in the [LICENSE](LICENSE) file.

- Total Composer downloads: [![Composer Downloads](https://poser.pugx.org/quiote/quiote/d/total.png)](https://packagist.org/packages/quiote/quiote)

[downloads page]: https://github.com/quioteframework/quiote/download
[github releases]: https://github.com/quiote/quiote/releases
