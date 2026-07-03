<?php

namespace Quiote\Http\Client\Exception;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * PSR-18 network failure: the request could not be sent / no response was
 * received (DNS failure, connection refused/reset, timeout). These are the
 * failures the retry policy treats as transient.
 */
final class NetworkException extends TransportException implements NetworkExceptionInterface
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
