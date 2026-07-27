# quioteframework/security-headers

Security response header middleware (CSP, `X-Frame-Options`, HSTS, etc.) for
[Quiote](https://github.com/quioteframework/quiote).

## Install

```
composer require quioteframework/security-headers
```

## Enable

Enabled by default (`security_headers.enabled` defaults to `true`) once the
package's plugin is registered. Configurable keys:

- `security_headers.csp` — full `Content-Security-Policy` value (default `default-src 'self'`)
- `security_headers.frame_options` — `X-Frame-Options` value (default `DENY`)
- `security_headers.content_type_options` — `X-Content-Type-Options` value (default `nosniff`)
- `security_headers.referrer_policy` — `Referrer-Policy` value (default `strict-origin-when-cross-origin`)
- `security_headers.permissions_policy` — `Permissions-Policy` value (default: omitted)
- `security_headers.hsts` — whether to send `Strict-Transport-Security` on https requests (default `true`)
- `security_headers.hsts_max_age` — HSTS `max-age` in seconds (default `15552000`, 180 days)

None of these headers are set if the response already carries that header, so
an action can always override the default on a per-route basis.

## License

MIT. See [LICENSE](LICENSE).
