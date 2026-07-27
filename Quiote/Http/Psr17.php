<?php
namespace Quiote\Http;

use Nyholm\Psr7\Factory\Psr17Factory;

/**
 * Shared stateless Nyholm\Psr7\Factory\Psr17Factory instance. Psr17Factory
 * holds no per-request state (it's pure construction logic for
 * request/response/stream/uri/upload-file objects), so allocating a fresh one
 * per response on the hot pipeline path is pure waste.
 */
final class Psr17
{
    private static ?Psr17Factory $instance = null;

    private function __construct() {}

    public static function factory(): Psr17Factory
    {
        return self::$instance ??= new Psr17Factory();
    }
}
