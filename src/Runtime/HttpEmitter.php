<?php
namespace Agavi\Runtime;

use Psr\Http\Message\ResponseInterface;

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
        echo (string) $response->getBody();
    }
}
