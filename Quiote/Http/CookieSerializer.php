<?php

namespace Quiote\Http;

use Psr\Http\Message\ResponseInterface;
use Quiote\Response\CookieSerializer as Serializer;

/**
 * Bridges cookies queued on a Quiote response onto a PSR-7 response's `Set-Cookie` headers.
 *
 * The response is duck-typed rather than declared, so a middleware can hand over whatever
 * the controller gave it without first proving it is a {@see \Quiote\Response\WebResponse}.
 * The serialization itself belongs to {@see \Quiote\Response\CookieSerializer}, which is the
 * one implementation of the `Set-Cookie` format in the framework; this class only locates the
 * cookies and merges the resulting lines.
 */
final class CookieSerializer
{
    /**
     * Append Set-Cookie headers from $globalResp to $response.
     *
     * A cookie whose serialized form is already present is not added twice, so a response
     * that passed through more than one bridging point carries each cookie once.
     *
     * @param  object            $globalResp  Quiote web response object (duck-typed).
     * @param  ResponseInterface $response    PSR-7 response to append headers to.
     * @param  string            $basePath    Default path for cookies without explicit path.
     * @return ResponseInterface The (immutably) updated response.
     */
    public static function bridge(object $globalResp, ResponseInterface $response, string $basePath = '/'): ResponseInterface
    {
        if (!method_exists($globalResp, 'getCookies')) {
            return $response;
        }

        try {
            $cookies = $globalResp->getCookies();
        } catch (\Throwable) {
            return $response;
        }

        if (!is_array($cookies) || $cookies === []) {
            return $response;
        }

        $serializer = new Serializer($basePath);
        foreach ($cookies as $name => $values) {
            if (!is_array($values) || !self::isSendable($values)) {
                continue;
            }

            try {
                $line = $serializer->header((string) $name, $serializer->normalize((string) $name, $values));
            } catch (\Throwable $e) {
                \Quiote\Logging\Log::create(self::class)->warning(
                    '[CookieSerializer] skipping cookie "' . $name . '": ' . $e->getMessage()
                );
                continue;
            }

            if (!in_array($line, $response->getHeader('Set-Cookie'), true)) {
                $response = $response->withAddedHeader('Set-Cookie', $line);
            }
        }

        return $response;
    }

    /**
     * Whether a duck-typed cookie definition is well-formed enough to send.
     *
     * This boundary accepts whatever a response object happens to hold, so a definition it
     * cannot make sense of is dropped rather than guessed at. A null value is a definition
     * that never got one, not a deletion request -- deletion is expressed as `false` or the
     * empty string, which {@see \Quiote\Response\WebResponse::unsetCookie()} uses.
     *
     * @param array<array-key, mixed> $values
     */
    private static function isSendable(array $values): bool
    {
        $value = $values['value'] ?? null;
        if ($value === null) {
            return false;
        }
        if (!is_scalar($value) && !$value instanceof \Stringable) {
            return false;
        }

        if (!array_key_exists('lifetime', $values)) {
            return true;
        }
        $lifetime = $values['lifetime'];

        return $lifetime === null || is_int($lifetime) || is_float($lifetime) || is_string($lifetime);
    }
}
