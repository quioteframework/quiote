<?php
namespace Agavi\Runtime;

use Psr\Http\Message\ResponseInterface;

class HttpEmitter
{
    public function emit(ResponseInterface $response): void
    {
        http_response_code($response->getStatusCode());
        foreach($response->getHeaders() as $name => $values) {
            foreach($values as $v) {
                header($name . ': ' . $v, false);
            }
        }
        echo (string) $response->getBody();
    }
}
