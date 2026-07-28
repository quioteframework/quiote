<?php

declare(strict_types=1);

namespace Quiote\Runtime\Swoole;

use Swoole\Http\Request as SwooleHttpRequest;

/**
 * The only place in this package that touches \Swoole\Http\Request. Kept to a
 * single method so everything else stays testable without ext-swoole.
 */
final class SwooleRequestSnapshotFactory
{
    private function __construct()
    {
    }

    public static function fromSwoole(SwooleHttpRequest $request): SwooleRequestSnapshot
    {
        $rawContent = $request->rawContent();

        return new SwooleRequestSnapshot(
            server: $request->server ?? [],
            header: $request->header ?? [],
            get: $request->get ?? [],
            post: $request->post ?? [],
            cookie: $request->cookie ?? [],
            files: $request->files ?? [],
            // rawContent() returns false for a request with no body at all.
            rawContent: is_string($rawContent) ? $rawContent : '',
        );
    }
}
