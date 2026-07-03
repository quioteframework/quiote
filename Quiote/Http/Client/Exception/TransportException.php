<?php

namespace Quiote\Http\Client\Exception;

use Psr\Http\Client\ClientExceptionInterface;

/**
 * Base PSR-18 client exception for the Quiote HTTP client — anything that went
 * wrong sending a request that isn't more specifically a network or malformed-
 * request failure ({@see NetworkException}/{@see RequestException}).
 */
class TransportException extends \RuntimeException implements ClientExceptionInterface
{
}
