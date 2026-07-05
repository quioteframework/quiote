<?php

namespace Quiote\Http\Client;

use Psr\Http\Client\ClientInterface;

/**
 * Chooses the default underlying PSR-18 transport: Guzzle if it is installed
 * (its `GuzzleHttp\Client` already implements PSR-18 `ClientInterface`, so it is
 * used directly — no adapter needed), otherwise the zero-dependency
 * {@see CurlTransport}. Callers can override per named client via
 * {@see HttpClientConfig::$transport} or globally via
 * {@see HttpClientFactory::setDefaultTransportFactory()}.
 */
final class TransportFactory
{
    public static function default(): ClientInterface
    {
        if (class_exists(\GuzzleHttp\Client::class)) {
            $client = new \GuzzleHttp\Client();
            if ($client instanceof ClientInterface) {
                return $client;
            }
        }
        return new CurlTransport();
    }
}
