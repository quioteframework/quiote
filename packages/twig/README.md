# quioteframework/twig

Twig (`.twig`) template renderer for [Quiote](https://github.com/quioteframework/quiote), built on [twig/twig](https://twig.symfony.com/).

## Install

```
composer require quioteframework/twig
```

## Enable

```xml
<renderers default="twig">
    <renderer name="twig" class="Quiote\Renderer\Twig\TwigRenderer">
        <parameter name="auto_reload">true</parameter>
        <parameter name="strict_variables">false</parameter>
        <parameter name="autoescape">html</parameter>
    </renderer>
</renderers>
```

All three parameters above are optional and shown at their defaults. Compiled templates are cached under `<core.cache_dir>/templates/twig/`.

Template resolution (directory conventions, locale fallback) is entirely handled by Quiote's own `TemplateLayer`, not Twig's filesystem loader — a template name in Twig error messages will be the fully-resolved path Quiote picked, not a Twig-relative name.

## License

MIT. See [LICENSE](LICENSE).
