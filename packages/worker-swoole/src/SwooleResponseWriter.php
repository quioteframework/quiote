<?php

declare(strict_types=1);

namespace Quiote\Runtime\Swoole;

use Swoole\Http\Response as SwooleHttpResponse;

/**
 * The only place in this package that touches \Swoole\Http\Response. Pure
 * delegation, so there is nothing here that needs testing without the extension.
 */
final class SwooleResponseWriter implements SwooleResponseWriterInterface
{
    public function __construct(private readonly SwooleHttpResponse $response)
    {
    }

    /** {@inheritDoc} */
    public function status(int $code): void
    {
        $this->response->status($code);
    }

    /** {@inheritDoc} */
    public function header(string $name, string|array $value): void
    {
        $this->response->header($name, $value);
    }

    /** {@inheritDoc} */
    public function write(string $chunk): bool
    {
        return $this->response->write($chunk);
    }

    /** {@inheritDoc} */
    public function end(string $body = ''): void
    {
        $this->response->end($body);
    }
}
