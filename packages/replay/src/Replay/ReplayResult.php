<?php

declare(strict_types=1);

namespace Quiote\Replay\Replay;

use Psr\Http\Message\ResponseInterface;

/** What one call to {@see ReplayEngine::replay()} produced. */
final readonly class ReplayResult
{
    public function __construct(
        public ResponseInterface $response,
        public DriftReport $drift,
    ) {
    }
}
