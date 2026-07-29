<?php

// No namespace: loaded via composer classmap for test/lib

use Quiote\Session\SessionPersistenceInterface;

/**
 * In-memory SessionPersistenceInterface for tests. $rows is public so a test
 * can inspect or doctor what was actually persisted -- notably the redirect
 * tombstone SessionManager writes on regeneration.
 */
final class InMemorySessionPersistence implements SessionPersistenceInterface
{
    /** @var array<string, array<string, mixed>> */
    public array $rows = [];

    public function load(string $sid): ?array
    {
        return $this->rows[$sid] ?? null;
    }

    public function save(string $sid, array $data): void
    {
        $this->rows[$sid] = $data;
    }

    public function delete(string $sid): void
    {
        unset($this->rows[$sid]);
    }
}
