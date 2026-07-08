<?php
namespace Quiote\Execution;

use ArrayAccess; use IteratorAggregate; use Countable; use ArrayIterator; use Traversable;

/**
 * Simple immutable-style attribute bag for no-container execution path.
 * Provides a focused API; mutation returns a cloned instance.
 * @implements ArrayAccess<string, mixed>
 * @implements IteratorAggregate<string, mixed>
 */
class AttributeBag implements ArrayAccess, IteratorAggregate, Countable
{
    /** @param array<string, mixed> $data */
    public function __construct(private array $data = [])
    {
    }
    /** @return array<string, mixed> */
    public function all(): array { return $this->data; }
    public function has(string $key): bool { return array_key_exists($key, $this->data); }
    /** @param mixed $default */
    public function get(string $key, $default = null): mixed { return $this->data[$key] ?? $default; }
    /** @param mixed $value */
    public function with(string $key, $value): self { $clone = clone $this; $clone->data[$key] = $value; return $clone; }
    public function without(string $key): self { if(!array_key_exists($key,$this->data)) { return $this; } $clone = clone $this; unset($clone->data[$key]); return $clone; }
    /** @param array<string, mixed> $values */
    public function merge(array $values): self { if(!$values) { return $this; } $clone = clone $this; foreach($values as $k=>$v){ $clone->data[$k]=$v; } return $clone; }
    // ArrayAccess (mutable for interoperability; callers wanting immutability use with()/without())
    public function offsetExists($offset): bool { return isset($this->data[$offset]) || array_key_exists($offset,$this->data); }
    public function offsetGet($offset): mixed { return $this->data[$offset] ?? null; }
    public function offsetSet($offset, $value): void
    {
        if (!is_string($offset)) {
            throw new \InvalidArgumentException('AttributeBag keys must be strings, ' . get_debug_type($offset) . ' given.');
        }
        $this->data[$offset] = $value;
    }
    public function offsetUnset($offset): void { unset($this->data[$offset]); }
    // Iteration / Countable
    public function getIterator(): Traversable { return new ArrayIterator($this->data); }
    public function count(): int { return count($this->data); }
}
