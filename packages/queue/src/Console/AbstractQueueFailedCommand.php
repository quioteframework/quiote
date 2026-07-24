<?php

namespace Quiote\Queue\Console;

use Quiote\Config\Config;
use Quiote\Console\Command\AbstractAppCommand;
use Quiote\Context;
use Quiote\DI\Container;
use Quiote\Queue\FailedJobStoreInterface;
use Quiote\Queue\InspectableFailedJobStoreInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Shared plumbing for the `queue:failed:*` commands. */
abstract class AbstractQueueFailedCommand extends AbstractAppCommand
{
    protected function resolveContainer(): Container
    {
        return Context::getInstance(Config::getString('core.default_context', 'web'))->getContainer();
    }

    /**
     * Resolves the configured {@see FailedJobStoreInterface}, or null (with an
     * error already written to $io) if it doesn't support inspection — the
     * default {@see \Quiote\Queue\LogFailedJobStore} only logs and drops, so
     * there is nothing to list/retry/forget.
     */
    protected function resolveInspectableStore(Container $container, SymfonyStyle $io): ?InspectableFailedJobStoreInterface
    {
        $store = $container->get(FailedJobStoreInterface::class);
        if (!$store instanceof InspectableFailedJobStoreInterface) {
            $io->error(sprintf(
                'The configured failed-job store (%s) does not support inspection. Bind a persistent store '
                . '(e.g. quioteframework/queue-db\'s DbFailedJobStore) to %s to use this command.',
                get_debug_type($store),
                FailedJobStoreInterface::class,
            ));
            return null;
        }
        return $store;
    }
}
