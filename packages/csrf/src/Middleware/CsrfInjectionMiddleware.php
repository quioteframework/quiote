<?php

namespace Quiote\Security\Csrf\Middleware;

use Quiote\Controller\Controller;
use Quiote\Security\Csrf\CsrfManager;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Delivers the CSRF token to clients so they can echo it back on unsafe requests.
 * Two delivery channels, applied on the outgoing response:
 *  1. Server-rendered HTML — for each `<form>` whose method is not GET (and that
 *     doesn't already carry the token field or opt out with `data-csrf="off"`),
 *     a hidden `<input name="<field>" ...>` is inserted after the opening tag,
 *     plus a `<meta name="csrf-token">` in `<head>`.
 *  2. A readable (non-HttpOnly) `XSRF-TOKEN` cookie — for any request that
 *     carries a session cookie, regardless of content type. This is how a
 *     same-origin SPA served from a *different* service/pod (which never sees
 *     our rendered HTML or meta tag) obtains the token: it reads the cookie from
 *     document.cookie and sends it back in the configured header
 *     (default X-CSRF-Token) on POST/PUT/PATCH/DELETE. The cookie is Secure on
 *     HTTPS and SameSite=Lax, and deliberately NOT HttpOnly so JS can read it —
 *     which is safe because a cross-origin attacker cannot read our cookies and
 *     the SameSite policy keeps them off cross-site requests.
 * Runs in the after_action phase; it wraps the response, so even a 403 from
 * CsrfValidationMiddleware carries a fresh token cookie for the client to retry
 * with. Operates on the serialized HTML independently of the Form Population
 * filter (which only runs when there is data to repopulate), so fresh forms get
 * a token too. */
#[\Quiote\Middleware\Attribute\Middleware(phase: 'before_action', priority: 45)]
class CsrfInjectionMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Controller $controller)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $csrf = new CsrfManager($this->controller->getContext());
        if (!$csrf->isEnabled()) {
            return $response;
        }

        // Deliver the token as a readable cookie whenever the request carries a
        // session (the ambient-credential case CSRF actually protects). This is
        // the only channel a decoupled same-origin SPA has, so it applies to all
        // content types, not just HTML.
        $setCookie = $this->hasSessionCookie($request);

        // Form/meta injection applies to HTML and XHTML responses. XHTML is
        // frequently served as text/html, but pages that set the proper XML type
        // (application/xhtml+xml) must be caught too, or their forms get no token.
        $contentType = $response->getHeaderLine('Content-Type');
        $isHtml = $contentType === ''
            || stripos($contentType, 'text/html') !== false
            || stripos($contentType, 'application/xhtml+xml') !== false;
        $html = null;
        $injectForms = false;
        if ($isHtml) {
            $html = (string) $response->getBody();
            $injectForms = $html !== '' && stripos($html, '<form') !== false;
        }

        if (!$setCookie && !$injectForms) {
            return $response;
        }

        // Resolve the token once and reuse for both channels.
        $token = $csrf->getTokenValue();

        if ($setCookie) {
            $response = $this->withXsrfCookie($request, $response, $csrf->cookieName(), $token);
        }

        if ($injectForms) {
            $newHtml = $this->inject($html, $csrf->fieldName(), $token);
            if ($newHtml !== $html) {
                $factory = new Psr17Factory();
                $response = $response->withBody($factory->createStream($newHtml));
            }
        }

        return $response;
    }

    /**
     * Append a readable XSRF-TOKEN cookie carrying the current token.
     */
    private function withXsrfCookie(ServerRequestInterface $request, ResponseInterface $response, string $name, string $token): ResponseInterface
    {
        $parts = [
            $name . '=' . rawurlencode($token),
            'Path=/',
            'SameSite=Lax',
        ];
        if ($this->isHttps($request)) {
            $parts[] = 'Secure';
        }
        // Deliberately NOT HttpOnly: the SPA must read this from document.cookie.
        return $response->withAddedHeader('Set-Cookie', implode('; ', $parts));
    }

    /**
     * Whether the request carries the configured session cookie.
     */
    private function hasSessionCookie(ServerRequestInterface $request): bool
    {
        $cookies = $request->getCookieParams();
        if (!is_array($cookies) || $cookies === []) {
            return false;
        }
        $name = session_name();
        return isset($cookies[$name]) && $cookies[$name] !== '';
    }

    private function isHttps(ServerRequestInterface $request): bool
    {
        try {
            if (strtolower((string) $request->getUri()->getScheme()) === 'https') {
                return true;
            }
        } catch (\Throwable) {
        }
        $server = $request->getServerParams();
        if (isset($server['HTTPS']) && $server['HTTPS'] !== '' && strtolower((string) $server['HTTPS']) !== 'off') {
            return true;
        }
        $xfp = strtolower(trim($request->getHeaderLine('X-Forwarded-Proto')));
        return $xfp !== '' && str_starts_with($xfp, 'https');
    }

    /**
     * Insert the hidden token field into non-GET forms and a meta tag into head.
     */
    private function inject(string $html, string $field, string $token): string
    {
        // Self-closing tags: valid HTML5 (the trailing slash on void elements is
        // ignored) AND well-formed when the document is XHTML parsed as XML — so a
        // single form works for both text/html and application/xhtml+xml responses.
        $hidden = '<input type="hidden" name="' . htmlspecialchars($field, ENT_QUOTES) . '" value="' . htmlspecialchars($token, ENT_QUOTES) . '" />';

        $html = preg_replace_callback(
            '/<form\b[^>]*>/i',
            function (array $m) use ($field, $hidden): string {
                $tag = $m[0];
                // method: default GET when omitted; only protect state-changing forms.
                $method = 'get';
                if (preg_match('/\bmethod\s*=\s*("|\')?\s*([a-z]+)/i', $tag, $mm)) {
                    $method = strtolower($mm[2]);
                }
                if ($method === 'get') {
                    return $tag;
                }
                // Explicit opt-out.
                if (preg_match('/\bdata-csrf\s*=\s*("|\')?\s*off/i', $tag)) {
                    return $tag;
                }
                return $tag . $hidden;
            },
            $html
        );

        if ($html === null) {
            // preg error (e.g. PCRE backtrack limit on huge input): leave untouched.
            return $token === '' ? '' : $html ?? '';
        }

        // Add a meta tag for JS clients (once), if a <head> exists and none present.
        if (stripos($html, 'name="csrf-token"') === false && preg_match('/<head\b[^>]*>/i', $html)) {
            $meta = '<meta name="csrf-token" content="' . htmlspecialchars($token, ENT_QUOTES) . '" />';
            $html = preg_replace('/(<head\b[^>]*>)/i', '$1' . $meta, $html, 1);
        }

        return (string) $html;
    }
}
