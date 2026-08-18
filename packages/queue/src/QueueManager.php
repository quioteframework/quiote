<?php

namespace Quiote\Queue;

use Quiote\DI\Container;
use Quiote\Support\Clock\ClockInterface;
use Quiote\Support\Clock\SystemClock;

/**
 * App-facing entry point: `$container->get(QueueManager::class)->push(SendWelcomeEmail::class, ['userId' => 5])`.
 * Resolves the configured driver (or an explicit alias) from
 * {@see QueueDriverRegistry} via {@see Container::get()} — a driver is a
 * long-lived service (memoized like any other), not a fresh-per-call
 * action/view, so a persistent driver's own service factory (e.g.
 * `quioteframework/queue-db`'s `QueueDbPlugin` resolving a real PDO
 * connection) runs instead of raw constructor autowiring.
 */
final readonly class QueueManager
{
    public function __construct(
        private Container $container,
        private QueueConfig $config,
        private ClockInterface $clock = new SystemClock(),
    ) {
    }

    /**
     * @param class-string<Job> $jobClass
     * @param array<string, mixed> $params
     */
    public function push(string $jobClass, array $params = [], ?int $delaySeconds = null): void
    {
        $availableAt = $delaySeconds !== null ? $this->clock->now()->modify(sprintf('+%d seconds', $delaySeconds)) : null;
        $this->driver()->push(new JobPayload($jobClass, $params, 0, $availableAt));
    }

    /**
     * Resolves a queue driver by alias, defaulting to `queue.default_driver`.
     *
     * The alias is translated to a class through {@see QueueDriverRegistry} and
     * the instance comes from the container, so a driver registered with its
     * own service factory is built by that factory and memoized as a singleton.
     *
     * @throws \RuntimeException if the alias is unknown to the registry, or the
     *         resolved service does not implement {@see QueueDriverInterface}.
     */
    public function driver(?string $alias = null): QueueDriverInterface
    {
        $class = QueueDriverRegistry::instantiateClassFor($alias ?? $this->config->defaultDriver);

        $driver = $this->container->get($class);
        if (!$driver instanceof QueueDriverInterface) {
            throw new \RuntimeException(sprintf(
                'Queue driver class "%s" must implement %s, got %s.',
                $class,
                QueueDriverInterface::class,
                get_debug_type($driver),
            ));
        }

        return $driver;
    }
}
