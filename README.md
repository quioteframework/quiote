# Quiote

- License: LGPL
- Latest Version: [![Latest Stable Version](https://poser.pugx.org/quiote/quiote/version.png)](https://packagist.org/packages/quiote/quiote)
- Build: [![Build Status](https://secure.travis-ci.org/quiote/quiote.png)](http://travis-ci.org/quiote/quiote)
- Homepage: [https://github.com/jakamoltd/quiote/](https://github.com/jakamoltd/quiote/)
- Releases: see [downloads page] or [github releases]

## Purpose

Quiote is a *powerful, scalable PHP5 application framework* that follows the MVC
paradigm. It enables developers to write clean, maintainable and extensible
code. Quiote puts choice and freedom over limiting conventions, and focuses on
sustained quality rather than short-sighted decisions.

Quiote is designed for serious development. It is not a complete website
construction kit but rather a skeleton over which you build your application.
The architecture of Quiote allows developers to retain very fine control over
their code.

Quiote strives to leave most implementational choices to the developers. Quiote's
components are inherently extensible, and the framework itself is designed
around a XML-based configuration system that provides a very flexible
environment.

The framework works for almost all kinds of applications but excels most in
large codebases, long-term projects, extreme cases of integration and other
special situations. Creating an application that is accessible not only as
a standard web application but also via a commandline interface or standards
like HTTP, SOAP or even XML-RPC is a perfectly valid use case.

## Requirements and installation

- PHP v5.2.0+ (recommended is 5.2.8 or higher)
- required: `libxml`, `dom`, `SPL`, `Reflection` and `PCRE`
- optional: `xsl`, `tokenizer`, `session`, `xmlrpc`, `soap`, `PDO`, `iconv`, `gettext`, `phing`

See the [installation guide](https://github.com/jakamoltd/quiote/documentation/tutorial/quiote-installation.html)
in the tutorial for some details. Installation via [Composer](http://getcomposer.org/)/[Packagist](http://packagist.com/)
and git clone is not mentioned there, but available by typing ```composer
require quiote/quiote [optional version]```. Adding Quiote manually as a vendor
library requirement to the `composer.json` file of your project works as well:

```json
{
    "require": {
        "quiote/quiote": "~1.0.0"
    }
}
```

Alternatively, you can download a release archive from the [github releases]
page and extract it or see the [downloads page] on the homepage.

## Documentation

An introduction into Quiote can be found in form of a [tutorial](https://github.com/jakamoltd/quiote/documentation/tutorial)
for a blog application. There are [API docs](https://github.com/jakamoltd/quiote/apidocs/)
and an [official FAQ](https://github.com/quiote/quiote/wiki/FAQ) as well as slightly outdated [WTF](https://github.com/quiote/quiote/wiki/WTF)
and [blog](http://blog.quiote.org/). A [useful FAQ for developers](http://mivesto.de/quiote/quiote-faq.html)
may help with common questions while browsing the [source files](src) with their docs is always an option.

## Support

To get support have a look at the [support page](https://github.com/jakamoltd/quiote/support) on the homepage.
There are mailing lists to join and a helpful [freenode IRC channel](https://github.com/quiote/quiote/wiki/IRC)
named `#quiote` to get you up to speed (```irc://irc.freenode.org/quiote```).
The [IRC channel logs](https://github.com/jakamoltd/quiote/irclogs/) are available for the
curious that are interested in past conversations.

## Contribution

Discussing issues on the mailing lists or in github issues as well as talking
about problems and features in the IRC channel is always of good help to
everyone. If you want to do more please contribute by [forking](https://help.github.com/forking/)
and sending a [pull request](https://help.github.com/pull-requests/). More
information can be found in the [CONTRIBUTING.md](CONTRIBUTING.md) file.

## Changelog

See the latest changes in the [repository CHANGELOG](CHANGELOG) or on the [homepage](https://github.com/jakamoltd/quiote/download/1.0.7/changelog).
The [1.0 release notes](RELEASE_NOTES-1.0) or [upcoming release notes](RELEASE_NOTES)
may be helpful as well.

## License

Quiote is licensed under the <a rel="license" href="https://en.wikipedia.org/wiki/GNU_Lesser_General_Public_License">LGPL 2.1</a>.
See the [Open Source Initiative](http://opensource.org/licenses/LGPL-2.1)
and [this FAQ entry](https://github.com/quiote/quiote/wiki/FAQ#wiki-can-i-use-quiote-in-a-proprietary-commercial-application)
for details. All relevant licenses and details can be found in the [LICENSE](LICENSE) file.

- Total Composer downloads: [![Composer Downloads](https://poser.pugx.org/quiote/quiote/d/total.png)](https://packagist.org/packages/quiote/quiote)

[downloads page]: https://github.com/jakamoltd/quiote/download
[github releases]: https://github.com/quiote/quiote/releases