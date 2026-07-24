<?php

namespace Quiote\Queue;

/**
 * A {@see FailedJobStoreInterface} whose dead-letter records can be listed,
 * looked up, and removed — the query side needed by
 * `queue:failed:list`/`queue:failed:retry`/`queue:failed:forget` (see
 * {@see \Quiote\Queue\Console\QueueFailedListCommand} and friends).
 * Deliberately not part of the base interface: the default
 * {@see LogFailedJobStore} only logs and drops, so it has nothing to query.
 * `quioteframework/queue-db`'s `DbFailedJobStore` implements this.
 */
interface InspectableFailedJobStoreInterface extends FailedJobStoreInterface
{
    /** @return list<FailedJobRecord> */
    public function list(int $limit = 50, int $offset = 0): array;

    public function count(): int;

    public function find(string $id): ?FailedJobRecord;

    public function delete(string $id): void;
}
