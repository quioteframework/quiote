<?php

// No namespace: loaded via composer classmap for test/lib

/**
 * An InMemorySessionBag that records its calls into a shared log, so a test can
 * assert the *order* of things across more than one collaborator -- notably
 * that the user is persisted before the session is.
 */
class RecordingSessionBag extends InMemorySessionBag
{
    /** @var list<string> */
    public static array $log = [];

    public static function resetLog(): void
    {
        self::$log = [];
    }

    /** @return list<string> */
    public static function calls(): array
    {
        return self::$log;
    }

    #[\Override]
    public function set(string $key, mixed $value): void
    {
        self::$log[] = 'bag.set:' . $key;
        parent::set($key, $value);
    }

    #[\Override]
    public function destroy(): void
    {
        self::$log[] = 'bag.destroy';
        parent::destroy();
    }
}
