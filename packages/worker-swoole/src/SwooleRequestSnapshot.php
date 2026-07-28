<?php

declare(strict_types=1);

namespace Quiote\Runtime\Swoole;

/**
 * A Swoole HTTP request reduced to plain arrays.
 *
 * ext-swoole is a `suggest` rather than a `require`, so the conversion logic has
 * to be checkable and testable on a machine without the extension. Only
 * {@see SwooleRequestSnapshotFactory} names \Swoole\Http\Request; everything
 * downstream works off this.
 */
final readonly class SwooleRequestSnapshot
{
    /**
     * @param array<string, mixed>  $server  Swoole's own $request->server -- note the keys are LOWERCASE.
     * @param array<string, string> $header  Lowercase header names.
     * @param array<string, mixed>  $get
     * @param array<string, mixed>  $post
     * @param array<string, string> $cookie
     * @param array<string, mixed>  $files   $_FILES-shaped.
     */
    public function __construct(
        public array $server = [],
        public array $header = [],
        public array $get = [],
        public array $post = [],
        public array $cookie = [],
        public array $files = [],
        public string $rawContent = '',
    ) {
    }

    public function serverValue(string $key): ?string
    {
        $value = $this->server[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }
}
