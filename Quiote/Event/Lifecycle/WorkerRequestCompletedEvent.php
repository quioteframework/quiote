<?php

namespace Quiote\Event\Lifecycle;

use Quiote\Context;
use Quiote\Event\Event;

/**
 * Emitted once per request from {@see \Quiote\Runtime\Kernel}'s worker-mode
 * reset step — after {@see \Quiote\Util\WorkerManager::resetForNextRequest()}
 * (if worker mode is active), regardless of whether the request succeeded or
 * the pipeline threw. This is the per-request-boundary counterpart to
 * {@see KernelBootEvent}: a plugin that builds worker-lifetime state at boot
 * (e.g. a batching span/metric exporter) uses this to flush it before the
 * worker serves the next request, instead of `Kernel` naming that plugin's
 * class directly.
 *
 * Distinct from {@see ResponseSendingEvent}: that one fires inside
 * `Context::handle()` just before a *successful* response is returned, so it
 * never fires on a pre-pipeline failure. This one always fires, once per
 * request, at the outermost worker boundary.
 */
final class WorkerRequestCompletedEvent extends Event
{
    public function __construct(
        public readonly Context $context,
    ) {}
}
