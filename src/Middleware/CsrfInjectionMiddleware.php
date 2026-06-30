<?php

namespace Agavi\Middleware;

use Agavi\Controller\AgaviController;
use Agavi\Security\Csrf\CsrfManager;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Injects a CSRF token into every server-rendered HTML response so applications
 * don't have to add the hidden field by hand.
 *
 * For each `<form>` whose method is not GET (and that doesn't already carry the
 * token field or opt out with `data-csrf="off"`), a hidden input
 * `<input type="hidden" name="<field>" value="<token>">` is inserted right after
 * the opening tag. A `<meta name="csrf-token" content="<token>">` is also added
 * to `<head>` so JavaScript/fetch clients can send the token via the configured
 * header.
 *
 * Runs in the after_action phase on the outgoing response. It operates on the
 * serialized HTML (independent of the Form Population filter, which only runs
 * when there is data to repopulate), so fresh forms get a token too.
 *
 * @package    agavi
 * @subpackage middleware
 */
#[\Agavi\Middleware\Attribute\AgaviMiddleware(phase: 'after_action', after: 'FormPopulationMiddleware')]
class CsrfInjectionMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly AgaviController $controller)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $csrf = new CsrfManager($this->controller->getContext());
        if (!$csrf->isEnabled()) {
            return $response;
        }

        // Only touch HTML responses.
        $contentType = $response->getHeaderLine('Content-Type');
        if ($contentType !== '' && stripos($contentType, 'text/html') === false) {
            return $response;
        }

        $html = (string) $response->getBody();
        if ($html === '' || stripos($html, '<form') === false) {
            return $response;
        }

        $token = $csrf->getTokenValue();
        $field = $csrf->fieldName();
        $newHtml = $this->inject($html, $field, $token);
        if ($newHtml === $html) {
            return $response;
        }

        $factory = new Psr17Factory();
        return $response->withBody($factory->createStream($newHtml));
    }

    /**
     * Insert the hidden token field into non-GET forms and a meta tag into head.
     */
    private function inject(string $html, string $field, string $token): string
    {
        $hidden = '<input type="hidden" name="' . htmlspecialchars($field, ENT_QUOTES) . '" value="' . htmlspecialchars($token, ENT_QUOTES) . '">';

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
            $meta = '<meta name="csrf-token" content="' . htmlspecialchars($token, ENT_QUOTES) . '">';
            $html = preg_replace('/(<head\b[^>]*>)/i', '$1' . $meta, $html, 1);
        }

        return (string) $html;
    }
}
