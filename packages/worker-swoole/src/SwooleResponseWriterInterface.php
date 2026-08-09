<?php

declare(strict_types=1);

namespace Quiote\Runtime\Swoole;

/**
 * The slice of \Swoole\Http\Response the emitter needs, so the emitter can be
 * tested against a recording double on a machine with no ext-swoole.
 */
interface SwooleResponseWriterInterface
{
    /** Sets the response status code; must be called before any body is written. */
    public function status(int $code): void;

    /**
     * @param string|list<string> $value An array sends one header line per value,
     *        which is how multiple Set-Cookie headers survive.
     */
    public function header(string $name, string|array $value): void;

    /** @return bool False once the client is gone, which ends a stream early. */
    public function write(string $chunk): bool;

    /**
     * Finishes the response, optionally sending a final body.
     *
     * Called once per request. After a streamed body the argument is omitted,
     * because everything has already gone out through {@see self::write()}.
     */
    public function end(string $body = ''): void;
}
