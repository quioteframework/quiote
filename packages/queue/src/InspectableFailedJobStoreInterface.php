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

    /** Returns the total number of dead-letter records held, ignoring any paging. */
    public function count(): int;

    /** Returns the record with this id, or null if no such record is stored. */
    public function find(string $id): ?FailedJobRecord;

    /**
     * Removes the record with this id.
     *
     * Deleting an id that is not stored is not an error — implementors treat
     * it as a no-op, so `queue:failed:forget` and the retry command's
     * delete-after-requeue step stay idempotent.
     */
    public function delete(string $id): void;
}
