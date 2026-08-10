<?php

declare(strict_types=1);

namespace Quiote\Test\Redis;

use LogicException;
use Predis\ClientInterface;
use Predis\Command\CommandInterface;

/**
 * In-memory stand-in for a Predis client, covering the string, list and
 * sorted-set commands the Redis-backed session and queue drivers issue.
 *
 * Predis exposes commands through {@see ClientInterface::__call()}, so a
 * PHPUnit double can only stub that one magic entry point -- which makes an
 * expectation like "SETEX was called with this key and ttl" read as an
 * argument-matcher on a string method name. This fake keeps real state
 * instead, so a test can drive a backend through save/load/delete and assert
 * on the observable result rather than on the command sequence.
 *
 * Key expiry is driven by an explicit clock ({@see self::advanceTime()})
 * rather than wall time, so TTL behaviour is testable without sleeping.
 */
final class InMemoryPredisClient implements ClientInterface
{
    /** @var array<string, string> */
    private array $strings = [];

    /** @var array<string, list<string>> */
    private array $lists = [];

    /** @var array<string, array<string, float>> Member => score, per sorted set. */
    private array $zsets = [];

    /** @var array<string, int> Key => unix timestamp at which the key expires. */
    private array $expiries = [];

    /** @var list<string> Every command name issued, in order, upper-cased. */
    private array $commands = [];

    private int $now;

    private bool $connected = true;

    public function __construct(?int $now = null)
    {
        $this->now = $now ?? 1_700_000_000;
    }

    /**
     * Moves the fake clock forward, expiring any key whose TTL has elapsed.
     */
    public function advanceTime(int $seconds): void
    {
        $this->now += $seconds;
    }

    public function now(): int
    {
        return $this->now;
    }

    /**
     * The command names issued so far, upper-cased and in order.
     *
     * @return list<string>
     */
    public function commandLog(): array
    {
        return $this->commands;
    }

    /** @return list<string> Every live (unexpired) key, in insertion order. */
    public function keys(): array
    {
        $this->collectExpired();

        $keys = [];
        foreach ([...array_keys($this->strings), ...array_keys($this->lists), ...array_keys($this->zsets)] as $key) {
            if (!in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    // -- string commands ---------------------------------------------------

    public function get(string $key): ?string
    {
        $this->log('GET');
        $this->collectExpired();

        return $this->strings[$key] ?? null;
    }

    public function set(string $key, string $value): string
    {
        $this->log('SET');
        $this->strings[$key] = $value;
        unset($this->expiries[$key]);

        return 'OK';
    }

    public function setex(string $key, int $ttl, string $value): string
    {
        $this->log('SETEX');
        $this->strings[$key] = $value;
        $this->expiries[$key] = $this->now + $ttl;

        return 'OK';
    }

    /**
     * @param string|list<string> $keys
     */
    public function del(string|array $keys): int
    {
        $this->log('DEL');
        $deleted = 0;

        foreach ((array) $keys as $key) {
            if (isset($this->strings[$key]) || isset($this->lists[$key]) || isset($this->zsets[$key])) {
                ++$deleted;
            }
            unset($this->strings[$key], $this->lists[$key], $this->zsets[$key], $this->expiries[$key]);
        }

        return $deleted;
    }

    /**
     * Remaining lifetime in seconds: -2 when the key is gone, -1 when it has
     * no expiry, mirroring Redis's own TTL reply.
     */
    public function ttl(string $key): int
    {
        $this->log('TTL');
        $this->collectExpired();

        if (!isset($this->strings[$key]) && !isset($this->lists[$key]) && !isset($this->zsets[$key])) {
            return -2;
        }

        if (!isset($this->expiries[$key])) {
            return -1;
        }

        return $this->expiries[$key] - $this->now;
    }

    public function expire(string $key, int $ttl): int
    {
        $this->log('EXPIRE');
        $this->collectExpired();

        if (!isset($this->strings[$key]) && !isset($this->lists[$key]) && !isset($this->zsets[$key])) {
            return 0;
        }

        $this->expiries[$key] = $this->now + $ttl;

        return 1;
    }

    public function flushdb(): string
    {
        $this->log('FLUSHDB');
        $this->strings = [];
        $this->lists = [];
        $this->zsets = [];
        $this->expiries = [];

        return 'OK';
    }

    // -- list commands -----------------------------------------------------

    /**
     * @param string|list<string> $values
     */
    public function lpush(string $key, string|array $values): int
    {
        $this->log('LPUSH');
        $this->collectExpired();
        $list = $this->lists[$key] ?? [];

        foreach ((array) $values as $value) {
            array_unshift($list, $value);
        }

        $this->lists[$key] = $list;

        return count($list);
    }

    /**
     * @param string|list<string> $values
     */
    public function rpush(string $key, string|array $values): int
    {
        $this->log('RPUSH');
        $this->collectExpired();
        $list = $this->lists[$key] ?? [];

        foreach ((array) $values as $value) {
            $list[] = $value;
        }

        $this->lists[$key] = $list;

        return count($list);
    }

    /**
     * Pops the tail of `$source` onto the head of `$destination`, atomically
     * as far as any caller of this fake can tell. Returns null on an empty
     * source, leaving the destination untouched.
     */
    public function rpoplpush(string $source, string $destination): ?string
    {
        $this->log('RPOPLPUSH');
        $this->collectExpired();
        $list = $this->lists[$source] ?? [];
        $entry = array_pop($list);

        if ($entry === null) {
            return null;
        }

        $this->lists[$source] = $list;
        $this->lpush($destination, [$entry]);

        return $entry;
    }

    /**
     * Removes occurrences of `$value`; `$count` of 0 removes them all, a
     * positive count removes that many from the head.
     */
    public function lrem(string $key, int $count, string $value): int
    {
        $this->log('LREM');
        $this->collectExpired();
        $list = $this->lists[$key] ?? [];
        $removed = 0;
        $kept = [];

        foreach ($list as $entry) {
            if ($entry === $value && ($count === 0 || $removed < $count)) {
                ++$removed;
                continue;
            }
            $kept[] = $entry;
        }

        $this->lists[$key] = $kept;

        return $removed;
    }

    public function llen(string $key): int
    {
        $this->log('LLEN');
        $this->collectExpired();

        return count($this->lists[$key] ?? []);
    }

    /** @return list<string> */
    public function lrange(string $key, int $start, int $stop): array
    {
        $this->log('LRANGE');
        $this->collectExpired();
        $list = $this->lists[$key] ?? [];
        $length = $stop < 0 ? count($list) + $stop - $start + 1 : $stop - $start + 1;

        return array_slice($list, $start, max(0, $length));
    }

    // -- sorted-set commands -----------------------------------------------

    /**
     * @param array<string, float|int> $membersAndScores Member => score.
     */
    public function zadd(string $key, array $membersAndScores): int
    {
        $this->log('ZADD');
        $this->collectExpired();
        $added = 0;

        foreach ($membersAndScores as $member => $score) {
            if (!isset($this->zsets[$key][$member])) {
                ++$added;
            }
            $this->zsets[$key][(string) $member] = (float) $score;
        }

        return $added;
    }

    public function zrem(string $key, string $member): int
    {
        $this->log('ZREM');
        $this->collectExpired();

        if (!isset($this->zsets[$key][$member])) {
            return 0;
        }

        unset($this->zsets[$key][$member]);

        return 1;
    }

    /**
     * Members scored within `[$min, $max]`, ascending by score. Accepts the
     * `-inf`/`+inf` bounds Redis takes as strings.
     *
     * @return list<string>
     */
    public function zrangebyscore(string $key, string|float|int $min, string|float|int $max): array
    {
        $this->log('ZRANGEBYSCORE');
        $this->collectExpired();
        $set = $this->zsets[$key] ?? [];
        asort($set);

        $lower = $this->bound($min, -INF);
        $upper = $this->bound($max, INF);

        $matched = [];
        foreach ($set as $member => $score) {
            if ($score >= $lower && $score <= $upper) {
                $matched[] = (string) $member;
            }
        }

        return $matched;
    }

    public function zcard(string $key): int
    {
        $this->log('ZCARD');
        $this->collectExpired();

        return count($this->zsets[$key] ?? []);
    }

    public function zscore(string $key, string $member): ?float
    {
        $this->log('ZSCORE');
        $this->collectExpired();

        return $this->zsets[$key][$member] ?? null;
    }

    // -- ClientInterface ---------------------------------------------------

    #[\Override]
    public function getCommandFactory(): never
    {
        throw new LogicException(self::class . ' does not build real Predis commands.');
    }

    #[\Override]
    public function getOptions(): never
    {
        throw new LogicException(self::class . ' has no Predis options.');
    }

    #[\Override]
    public function connect(): void
    {
        $this->connected = true;
    }

    #[\Override]
    public function disconnect(): void
    {
        $this->connected = false;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    #[\Override]
    public function getConnection(): never
    {
        throw new LogicException(self::class . ' has no Predis connection.');
    }

    /**
     * @param string $method
     * @param array<int, mixed> $arguments
     */
    #[\Override]
    public function createCommand($method, $arguments = []): never
    {
        throw new LogicException(self::class . ' does not build real Predis commands.');
    }

    #[\Override]
    public function executeCommand(CommandInterface $command): never
    {
        throw new LogicException(self::class . ' does not execute real Predis commands.');
    }

    /**
     * Every command this fake understands is a real method, so reaching the
     * magic dispatcher means the code under test issued one it does not model
     * -- which is a gap in the fake, not something to answer with null.
     *
     * @param string $method
     * @param array<int, mixed> $arguments
     */
    #[\Override]
    public function __call($method, $arguments): never
    {
        throw new LogicException(sprintf(
            '%s does not implement the Redis command "%s". Add it there if the code under test needs it.',
            self::class,
            $method,
        ));
    }

    private function log(string $command): void
    {
        $this->commands[] = $command;
    }

    private function bound(string|float|int $value, float $infinite): float
    {
        if (is_string($value)) {
            $normalised = strtolower(trim($value));
            if ($normalised === '-inf' || $normalised === '+inf' || $normalised === 'inf') {
                return $normalised === '-inf' ? -INF : INF;
            }
            if (!is_numeric($normalised)) {
                return $infinite;
            }
        }

        return (float) $value;
    }

    private function collectExpired(): void
    {
        foreach ($this->expiries as $key => $expiresAt) {
            if ($expiresAt <= $this->now) {
                unset($this->strings[$key], $this->lists[$key], $this->zsets[$key], $this->expiries[$key]);
            }
        }
    }
}
