<?php

// No namespace: loaded via composer classmap for test/lib

/**
 * MockStorage that records its own lifecycle calls, plus the instance each call
 * landed on, so tests can assert *ordering* (user before storage) and *identity*
 * (the recreated storage, not the stale one left in the shutdown sequence).
 *
 * Kept separate from MockStorage rather than folded into it: several existing
 * Context tests assert against MockStorage's plain boolean flags, and adding
 * shared mutable state to that class would couple them to each other.
 */
class RecordingStorage extends MockStorage
{
    /**
     * Shared across instances on purpose: the whole point is to observe the
     * order of calls across the stale object and its replacement. Tests must
     * call resetLog() in setUp().
     *
     * @var list<array{call: string, oid: int}>
     */
    public static array $log = [];

    public static function resetLog(): void
    {
        self::$log = [];
    }

    /**
     * The recorded call names, in order, without the object ids.
     *
     * @return list<string>
     */
    public static function calls(): array
    {
        return array_map(static fn(array $entry): string => $entry['call'], self::$log);
    }

    /**
     * Object ids recorded for one call name, in order.
     *
     * @return list<int>
     */
    public static function callersOf(string $call): array
    {
        $matching = array_filter(self::$log, static fn(array $entry): bool => $entry['call'] === $call);

        return array_values(array_map(static fn(array $entry): int => $entry['oid'], $matching));
    }

    private function record(string $call): void
    {
        self::$log[] = ['call' => $call, 'oid' => spl_object_id($this)];
    }

    #[\Override]
    public function startup(): void
    {
        $this->record('storage.startup');
        parent::startup();
    }

    #[\Override]
    public function shutdown(): void
    {
        $this->record('storage.shutdown');
        parent::shutdown();
    }

    #[\Override]
    public function reset(): void
    {
        $this->record('storage.reset');
        parent::reset();
    }

    #[\Override]
    public function store(string $ns, mixed $value): void
    {
        $this->record('storage.store:' . $ns);
        parent::store($ns, $value);
    }
}
