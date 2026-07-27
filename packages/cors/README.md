# quioteframework/cors

CORS (Cross-Origin Resource Sharing) middleware for [Quiote](https://github.com/quioteframework/quiote).

## Install

```
composer require quioteframework/cors
```

## Enable

Set `cors.enabled` to `true` in your app's settings, plus `cors.allowed_origins`
(a list of origins, or `["*"]`). All other keys have sane defaults:

- `cors.allowed_origins` — list of allowed origins, or `["*"]` (default: `[]`, i.e. nothing allowed)
- `cors.allowed_methods` — default `GET, POST, PUT, PATCH, DELETE, OPTIONS`
- `cors.allowed_headers` — default: echoes the preflight's `Access-Control-Request-Headers`
- `cors.exposed_headers` — default: none
- `cors.allow_credentials` — default `false`
- `cors.max_age` — preflight cache duration in seconds, default `0` (no caching)

Requests without an `Origin` header pass through untouched. Preflight
(`OPTIONS` with `Access-Control-Request-Method`) requests are answered
directly with a 204 and never reach the action.

## License

MIT. See [LICENSE](LICENSE).
