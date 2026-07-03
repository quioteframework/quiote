<?php

namespace Quiote\Event\Lifecycle;

use Quiote\Context;
use Quiote\Event\Event;

/**
 * Emitted at the end of {@see \Quiote\Quiote::bootstrap()}, once settings are
 * loaded, plugins registered, and any requested contexts created. The earliest
 * whole-framework "we're up" moment app/plugin code can hook.
 */
final class KernelBootEvent extends Event
{
    /** @param array<string,Context> $contexts contexts created during this bootstrap (may be empty) */
    public function __construct(
        public readonly string $environment,
        public readonly array $contexts,
    ) {}
}
