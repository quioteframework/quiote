# quioteframework/mcp

MCP (Model Context Protocol) server capability for [Quiote](https://github.com/quioteframework/quiote) — expose your app's actions as MCP tools, resources, and prompts.

## Install

```
composer require quioteframework/mcp
```

## Enable

Add the plugin to your app's `plugins` config key:

```php
'plugins' => [\Quiote\Mcp\McpPlugin::class],
```

Then configure the `mcp.*` settings (transports, auth, exposed actions) and run:

```
quiote mcp:serve
```

## License

MIT. See [LICENSE](LICENSE).
