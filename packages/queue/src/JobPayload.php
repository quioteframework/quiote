<?php

namespace Quiote\Queue;

/**
 * A queued job identified by class + constructor params, not a serialized
 * object — on execution the class is rebuilt via
 * {@see \Quiote\DI\Container::make()}, so constructor-injected services
 * autowire normally. `$params` must be JSON-serializable for persistent
 * drivers (e.g. `quioteframework/queue-db`); the in-process sync driver has
 * no such restriction.
 *
 * `$jobClass` is deliberately typed as plain `string`, not
 * `class-string<Job>`: a persistent driver builds this DTO from stored data
 * (e.g. a DB row) that hasn't been validated yet — the guarantee is
 * established at the point of use instead ({@see JobExecutor::attempt()}'s
 * `instanceof Job` check), not at construction.
 */
final readonly class JobPayload
{
    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        public string $jobClass,
        public array $params = [],
        public int $attempts = 0,
        public ?\DateTimeImmutable $availableAt = null,
    ) {
    }

    public function withAttempts(int $attempts): self
    {
        return new self($this->jobClass, $this->params, $attempts, $this->availableAt);
    }
}
