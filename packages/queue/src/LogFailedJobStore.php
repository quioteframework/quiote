<?php

namespace Quiote\Queue;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Default {@see FailedJobStoreInterface}: logs the failure and drops it.
 * Enough for `sync`/dev usage; an app wanting inspectable dead-letter
 * storage binds `quioteframework/queue-db`'s `DbFailedJobStore` instead
 * (see {@see \Quiote\Plugin\PluginRegistrar::service()}'s set-if-absent rule).
 */
final readonly class LogFailedJobStore implements FailedJobStoreInterface
{
    public function __construct(private LoggerInterface $logger = new NullLogger())
    {
    }

    public function record(FailedJob $failedJob): void
    {
        $this->logger->error(sprintf(
            'Queue job "%s" failed permanently after %d attempt(s): %s: %s',
            $failedJob->jobClass,
            $failedJob->attempts,
            $failedJob->exceptionClass,
            $failedJob->exceptionMessage,
        ), [
            'jobClass' => $failedJob->jobClass,
            'params' => $failedJob->params,
            'attempts' => $failedJob->attempts,
            'exceptionTrace' => $failedJob->exceptionTrace,
        ]);
    }
}
