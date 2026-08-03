<?php

namespace Quiote\Response;

use Psr\Http\Message\ResponseInterface;
use Quiote\Http\SimpleStream;

/**
 * Assembles a PSR-7 response from already-resolved status, headers, cookies and body.
 *
 * The translation step every runtime shares, with no side effects on any output channel and
 * no dependency on a context, a request or response state: everything it needs arrives as a
 * plain value. Deciding *what* the status and headers should be belongs to
 * {@see WebResponse}; turning that decision into a PSR-7 message belongs here.
 *
 * @since      3.2.0
 */
final class PsrResponseBuilder
{
    /**
     * @param      int $status Status code, already validated.
     * @param      array<string, list<string>|string> $headers Header name => value(s).
     * @param      list<string> $setCookieLines Serialized `Set-Cookie` values.
     * @param      mixed $content String, scalar, stream resource, or null.
     * @param      bool $withBody False to emit headers only (a redirect, typically).
     * @param      ?string $sendfileHeaderName When set and $content is a plain-file resource,
     *             the file's path is handed to the front-end server through this header and
     *             no body is emitted.
     */
    public function build(
        int $status,
        array $headers,
        array $setCookieLines,
        mixed $content,
        bool $withBody = true,
        ?string $sendfileHeaderName = null,
    ): ResponseInterface {
        $response = \Quiote\Http\Psr17::factory()->createResponse($status);

        foreach ($headers as $name => $values) {
            $response = $response->withHeader((string) $name, $values);
        }
        foreach ($setCookieLines as $line) {
            $response = $response->withAddedHeader('Set-Cookie', $line);
        }

        if (!$withBody) {
            return $response;
        }

        if (is_resource($content) && $sendfileHeaderName !== null && $sendfileHeaderName !== '') {
            $path = self::plainFilePath($content);
            if ($path !== null) {
                return $response->withHeader($sendfileHeaderName, $path);
            }
        }

        if (is_resource($content)) {
            // Wrapped rather than read into a string, so the emitter can drain it
            // incrementally instead of committing the whole file to memory first.
            return $response->withBody(
                \Quiote\Http\Psr17::factory()->createStreamFromResource($content)
            );
        }

        return $response->withBody(SimpleStream::fromString(self::stringify($content)));
    }

    /**
     * The filesystem path behind a stream resource, or null when it is not a plain file and
     * so cannot be handed to a front-end server to serve.
     *
     * @param resource $resource
     */
    private static function plainFilePath(mixed $resource): ?string
    {
        $info = stream_get_meta_data($resource);
        if ($info['wrapper_type'] !== 'plainfile' || !isset($info['uri'])) {
            return null;
        }

        return self::stringify($info['uri']);
    }

    private static function stringify(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
