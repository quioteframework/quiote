<?php

declare(strict_types=1);

namespace Quiote\Replay\Replay;

use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

/**
 * What {@see StubbedHttpTransport} raises when the ledger has no recorded counterpart for a
 * request.
 *
 * A plain `\RuntimeException` was the wrong type, not the wrong decision. PSR-18 states that
 * `sendRequest()` throws a {@see ClientExceptionInterface} when it cannot send the request, so a
 * caller's correct `catch (ClientExceptionInterface)` did not catch this and
 * `Quiote\Http\Client\HttpClient::sendWithRetry()`, which drives retries off
 * `NetworkExceptionInterface`, could not see it either -- meaning the retry sequence
 * {@see \Quiote\Replay\Http\RecordingHttpTransport} deliberately records could never be
 * reproduced on replay.
 *
 * There was never a trade-off to make: `Quiote\Http\Client\Exception\TransportException` shows
 * the shape, extending `\RuntimeException` *and* implementing the PSR interface. This does the
 * same rather than depending on the framework's HTTP client from a replay stub.
 */
final class StubbedTransportException extends RuntimeException implements ClientExceptionInterface
{
}
