# quioteframework/phptal

PHPTAL (`.tal`) template renderer for [Quiote](https://github.com/quioteframework/quiote), built on [phptal/phptal](https://github.com/pornel/PHPTAL).

## Install

```
composer require quioteframework/phptal
```

## Enable

Add a `renderer` entry to the output type(s) that should use PHPTAL in your app's `output_types.xml`:

```xml
<renderers default="phptal">
    <renderer name="phptal" class="Quiote\Renderer\Phptal\PhptalRenderer" />
</renderers>
```

Compiled template classes are cached under `<core.cache_dir>/templates/phptal/`.

An `encoding` renderer parameter is honored if set:

```xml
<renderer name="phptal" class="Quiote\Renderer\Phptal\PhptalRenderer">
    <parameter name="encoding">UTF-8</parameter>
</renderer>
```

## License

MIT. See [LICENSE](LICENSE).
