<?php

namespace Quiote\Http\Client\Exception;

use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * PSR-18 malformed-request failure: the request itself is not a well-formed
 * HTTP request and could not even be attempted (e.g. an unusable/empty URI).
 * Not retried — retrying a malformed request can't help.
 */
final class RequestException extends TransportException implements RequestExceptionInterface
{
    public function __construct(
        string $message,
        private readonly RequestInterface $request,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
