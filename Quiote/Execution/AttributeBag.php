<?php
namespace Quiote\Execution;

use ArrayAccess; use IteratorAggregate; use Countable; use ArrayIterator; use Traversable;

/**
 * Simple immutable-style attribute bag for no-container execution path.
 * Provides a focused API; mutation returns a cloned instance.
 *
 * Keys follow PHP's array-key rules, so a numeric attribute name such as `'403'` is held
 * under the integer key `403` and found again by either spelling.
 *
 * @implements ArrayAccess<array-key, mixed>
 * @implements IteratorAggregate<array-key, mixed>
 */
class AttributeBag implements ArrayAccess, IteratorAggregate, Countable
{
    /** @param array<array-key, mixed> $data */
    public function __construct(private array $data = [])
    {
    }
    /** @return array<array-key, mixed> */
    public function all(): array { return $this->data; }
    /** Reports whether the bag holds an entry under the given key, including one whose value is null. */
    public function has(int|string $key): bool { return array_key_exists($key, $this->data); }
    /** @param mixed $default */
    public function get(int|string $key, $default = null): mixed { return $this->data[$key] ?? $default; }
    /** @param mixed $value */
    public function with(int|string $key, $value): self { $clone = clone $this; $clone->data[$key] = $value; return $clone; }
    /**
     * Returns a clone of the bag with the given key removed.
     *
     * The receiver is never modified. When the key is absent the same instance is
     * returned rather than a clone, since there is nothing to change.
     */
    public function without(int|string $key): self { if(!array_key_exists($key,$this->data)) { return $this; } $clone = clone $this; unset($clone->data[$key]); return $clone; }
    /** @param array<array-key, mixed> $values */
    public function merge(array $values): self { if(!$values) { return $this; } $clone = clone $this; foreach($values as $k=>$v){ $clone->data[$k]=$v; } return $clone; }
    // ArrayAccess (mutable for interoperability; callers wanting immutability use with()/without())
    /** Reports whether an entry exists under the offset, treating a stored null as present. */
    public function offsetExists($offset): bool { return isset($this->data[$offset]) || array_key_exists($offset,$this->data); }
    /** Returns the value stored under the offset, or null when nothing is stored there. */
    public function offsetGet($offset): mixed { return $this->data[$offset] ?? null; }
    /**
     * Stores a value under the offset, mutating this bag in place.
     *
     * Callers that want the immutable semantics of the bag should use with() instead.
     *
     * @throws \InvalidArgumentException If the offset is neither a string nor an integer,
     *                                   including the `$bag[] = ...` append form.
     */
    public function offsetSet($offset, $value): void
    {
        if (!is_string($offset) && !is_int($offset)) {
            throw new \InvalidArgumentException('AttributeBag keys must be strings or integers, ' . get_debug_type($offset) . ' given.');
        }
        $this->data[$offset] = $value;
    }
    /** Removes the entry under the offset, mutating this bag in place; an absent key is a no-op. */
    public function offsetUnset($offset): void { unset($this->data[$offset]); }
    // Iteration / Countable
    /**
     * Returns an iterator over the entries, keyed by attribute name.
     *
     * The iterator walks a snapshot taken at call time, so mutating the bag afterwards
     * does not affect an iteration already in progress.
     */
    public function getIterator(): Traversable { return new ArrayIterator($this->data); }
    /** Returns the number of entries currently held. */
    public function count(): int { return count($this->data); }
}
