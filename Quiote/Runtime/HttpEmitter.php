<?php
namespace Quiote\Runtime;

use Psr\Http\Message\ResponseInterface;
use Quiote\Http\Sse\SseStream;

class HttpEmitter
{
    public function emit(ResponseInterface $response): void
    {
        http_response_code($response->getStatusCode());
        // Remove any previously set Content-Type header to avoid duplicates (e.g., early kernel fallback)
        if (function_exists('header_remove')) {
            @header_remove('Content-Type');
        } else {
            // Fallback: send an empty replacement (won't fully remove in some SAPIs)
            header('Content-Type:');
        }
        foreach ($response->getHeaders() as $name => $values) {
            $replace = (strtolower((string) $name) === 'content-type');
            foreach ($values as $v) {
                header($name . ': ' . $v, $replace);
                // After first Content-Type, ensure subsequent same-named headers (if any) append if intentional
                $replace = false;
            }
        }
        $body = $response->getBody();
        if ($body instanceof SseStream) {
            $this->emitStreaming($body);
        } else {
            echo (string) $body;
        }
    }

    /**
     * Flushes each SSE event to the client as it's produced instead of
     * buffering the whole body, stopping early if the client disconnects.
     * Works unchanged under FrankenPHP worker mode: flush() inside a single
     * frankenphp_handle_request() callback streams to the connection just
     * like it does under classic PHP-FPM. Deliberately doesn't touch the
     * userland output-buffer stack (no ob_end_flush()/ob_implicit_flush())
     * -- doing so would also tear down any buffer a caller/test set up
     * around emit() itself; apps that need output_buffering disabled for
     * true byte-at-a-time delivery should do so at the php.ini/webserver
     * level (the X-Accel-Buffering: no header already covers nginx/Caddy
     * proxy buffering).
     */
    private function emitStreaming(SseStream $stream): void
    {
        @set_time_limit(0);
        $stream->writeTo(static function (string $chunk): bool {
            echo $chunk;
            @flush();
            return !connection_aborted();
        });
    }
}
