# quioteframework/xslt

XSLT (`.xsl`) template renderer for [Quiote](https://github.com/quioteframework/quiote), built on `ext-xsl`/`ext-dom` (no external library dependency).

## Install

```
composer require quioteframework/xslt
```

## Enable

Add a `renderer` entry to the output type(s) that should use XSLT in your app's `output_types.xml`:

```xml
<renderers default="xslt">
    <renderer name="xslt" class="Quiote\Renderer\Xslt\XsltRenderer" />
</renderers>
```

## How it renders

The stylesheet (the `.xsl` file resolved via the layer) transforms an XML document built from `$moreAssigns['inner']` (either a `DOMDocument` or an XML string). Each scalar/`Stringable` template attribute is passed through as a top-level XSLT parameter.

By default (`envelope` parameter, on by default) the inner document and every rendered slot are wrapped into one synthetic document under the `http://quiote.org/quiote/renderer/xslt/envelope/1.0` namespace:

```xml
<envelope xmlns="http://quiote.org/quiote/renderer/xslt/envelope/1.0">
    <inner>...</inner>
    <slots>
        <slot name="sidebar">...</slot>
    </slots>
</envelope>
```

This lets a stylesheet pull slot markup via XPath, which a plain XSLT string parameter can't carry. Set `envelope` to `false` to skip the wrapper and transform `inner` directly — slots aren't available in that mode (XSLT string parameters can't safely carry arbitrary markup).

## License

MIT. See [LICENSE](LICENSE).
