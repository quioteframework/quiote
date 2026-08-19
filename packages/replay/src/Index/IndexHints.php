<?php

declare(strict_types=1);

namespace Quiote\Replay\Index;

/**
 * The developer-supplied resolution hints from `quiote cassette:fetch`/`quiote replay --save`: a
 * key pasted straight out of a log line, or a date/hour narrowing a prefix scan. Every field is
 * optional -- a bare id with no hints at all is exactly the case a `log-analytics` index is for.
 */
final readonly class IndexHints
{
    public function __construct(
        public ?string $key = null,
        public ?string $date = null,
        public ?string $hour = null,
    ) {
    }
}
