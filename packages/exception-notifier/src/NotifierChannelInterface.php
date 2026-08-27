<?php

namespace Quiote\ExceptionNotifier;

use Throwable;

/**
 * A destination for exception notifications. Implement this (and, to be
 * buildable from `exception_notifier.channels` config, {@see NotifierChannelFactoryInterface})
 * and register the class under a driver alias via
 * {@see ExceptionNotifierChannelRegistry::register()} — no core or plugin
 * change required to add a new channel (Slack, PagerDuty, email, ...).
 */
interface NotifierChannelInterface
{
    /**
     * Deliver a notification for $exception. Let any failure propagate as a
     * thrown exception — {@see ExceptionNotificationListener} catches and
     * logs per channel so one failing channel never blocks the others.
     */
    public function notify(Throwable $exception, ExceptionNotificationContext $context): void;
}
