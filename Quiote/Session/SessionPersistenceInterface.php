<?php

declare(strict_types=1);

namespace Quiote\Session;

/**
 * Storage backend contract for SessionManager. Implementations own how session
 * data is serialized and where it lives (Postgres, Redis, etc.) — SessionManager
 * only deals in plain arrays keyed by session id.
 */
interface SessionPersistenceInterface
{
    /**
     * @return array<string, mixed>|null null if the session id is unknown.
     */
    public function load(string $sid): ?array;

    /**
     * @param array<string, mixed> $data
     */
    public function save(string $sid, array $data): void;

    public function delete(string $sid): void;
}
