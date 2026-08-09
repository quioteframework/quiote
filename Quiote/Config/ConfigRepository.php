<?php

namespace Quiote\Config;

use Quiote\Exception\ConfigurationException;

/**
 * An instance-backed store of configuration directives, with typed accessors that fail loudly
 * when a directive does not hold the shape the caller asked for.
 *
 * This is where the behaviour lives; {@see Config} is a static facade over one default
 * instance of it. Having a real object means a consumer can be handed a repository -- through
 * the container, or directly in a test -- instead of reaching into process-wide state, and two
 * configurations can exist side by side.
 *
 * A directive marked read-only cannot be overwritten or removed, and survives {@see clear()}
 * and {@see resetWorkerState()}.
 *
 * @since      3.2.0
 */
class ConfigRepository
{
    /** @var array<string|int, mixed> */
    private array $config = [];

    /** @var array<string|int, mixed> */
    private array $readonlies = [];

    /**
     * @param array<string|int, mixed> $config Initial directives.
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * The raw value of a directive, or $default when unset.
     *
     * Prefer the typed accessors: this cannot be checked at the call site, so a
     * wrongly-shaped value propagates silently instead of failing where it was configured.
     *
     * @return     mixed
     */
    public function get(string|int $name, mixed $default = null): mixed
    {
        if (isset($this->config[$name]) || \array_key_exists($name, $this->config)) {
            return $this->config[$name];
        }

        return $default;
    }

    /**
     * A directive as a string. Scalars are cast; an array has no sensible string form and is
     * rejected.
     *
     * @throws     ConfigurationException If unset with no default, or holding an array.
     */
    public function getString(string|int $name, ?string $default = null): string
    {
        $value = $this->get($name, $default);
        if ($value === null) {
            throw new ConfigurationException(\sprintf('Config directive "%s" is not set and no default was given.', $name));
        }

        return $this->toStringOrFail($name, $value);
    }

    /**
     * A directive as a string, or null when it genuinely is not set.
     *
     * For settings where "unconfigured" is itself meaningful, such as an absent environment
     * override, so a missing directive is not an error.
     *
     * @throws     ConfigurationException If the directive holds a non-scalar value.
     */
    public function getNullableString(string|int $name, ?string $default = null): ?string
    {
        $value = $this->get($name, $default);

        return $value === null ? null : $this->toStringOrFail($name, $value);
    }

    /**
     * @throws     ConfigurationException If unset with no default, or not holding an int.
     */
    public function getInt(string|int $name, ?int $default = null): int
    {
        $value = $this->get($name, $default);
        if (!\is_int($value)) {
            throw new ConfigurationException(\sprintf('Config directive "%s" is not a valid int, got %s.', $name, get_debug_type($value)));
        }

        return $value;
    }

    /**
     * An int value is widened to float without complaint.
     *
     * @throws     ConfigurationException If unset with no default, or not holding a float.
     */
    public function getFloat(string|int $name, ?float $default = null): float
    {
        $value = $this->get($name, $default);
        if (\is_int($value)) {
            return (float) $value;
        }
        if (!\is_float($value)) {
            throw new ConfigurationException(\sprintf('Config directive "%s" is not a valid float, got %s.', $name, get_debug_type($value)));
        }

        return $value;
    }

    /**
     * @throws     ConfigurationException If the directive is set but does not hold a bool.
     */
    public function getBool(string|int $name, bool $default = false): bool
    {
        $value = $this->get($name, $default);
        if (!\is_bool($value)) {
            throw new ConfigurationException(\sprintf('Config directive "%s" is not a valid bool, got %s.', $name, get_debug_type($value)));
        }

        return $value;
    }

    /**
     * @param      ?array<mixed> $default
     * @return     array<mixed>
     * @throws     ConfigurationException If unset with no default, or not holding an array.
     */
    public function getArray(string|int $name, ?array $default = null): array
    {
        $value = $this->get($name, $default);
        if (!\is_array($value)) {
            throw new ConfigurationException(\sprintf('Config directive "%s" is not a valid array, got %s.', $name, get_debug_type($value)));
        }

        return $value;
    }

    /**
     * A directive configurable as either a single string or an array of strings, normalized
     * to a list. A single string becomes a one-element list; unset or empty becomes empty.
     *
     * @param      array<string> $default
     * @return     array<int, string>
     * @throws     ConfigurationException If it holds anything other than a string or an array of scalars.
     */
    public function getStringList(string|int $name, array $default = []): array
    {
        $value = $this->get($name, $default);
        if ($value === null) {
            return [];
        }
        if (\is_string($value)) {
            return $value === '' ? [] : [$value];
        }
        if (\is_array($value)) {
            return array_map(static function ($item) use ($name) {
                if (!\is_scalar($item)) {
                    throw new ConfigurationException(\sprintf('Config directive "%s" contains a non-scalar entry, got %s.', $name, get_debug_type($item)));
                }

                return (string) $item;
            }, array_values($value));
        }

        throw new ConfigurationException(\sprintf('Config directive "%s" is not a valid string or array of strings, got %s.', $name, get_debug_type($value)));
    }

    /**
     * Whether a directive with the given name is present.
     *
     * A directive explicitly set to null still counts as present.
     */
    public function has(string|int $name): bool
    {
        return isset($this->config[$name]) || \array_key_exists($name, $this->config);
    }

    /**
     * Whether the named directive was set read-only and can no longer be
     * changed.
     *
     * {@see self::set()} refuses to overwrite such a directive and reports
     * false rather than throwing.
     */
    public function isReadonly(string|int $name): bool
    {
        return isset($this->readonlies[$name]);
    }

    /**
     * Set a directive, unless it is read-only, or already set and $overwrite is false.
     *
     * @return     bool Whether the directive was set.
     */
    public function set(string|int $name, mixed $value, bool $overwrite = true, bool $readonly = false): bool
    {
        if (isset($this->readonlies[$name])) {
            return false;
        }
        if (!$overwrite && $this->has($name)) {
            return false;
        }

        $this->config[$name] = $value;
        if ($readonly) {
            $this->readonlies[$name] = $value;
        }

        return true;
    }

    /**
     * Remove a directive, unless it is read-only.
     *
     * @return     bool Whether it was removed.
     */
    public function remove(string|int $name): bool
    {
        if (!$this->has($name) || isset($this->readonlies[$name])) {
            return false;
        }
        unset($this->config[$name]);

        return true;
    }

    /**
     * Import directives, in precedence order: a read-only directive keeps its value, then the
     * incoming data wins, then anything already set that the data does not mention.
     *
     * Merged with `+` rather than array_merge(), which would reindex numeric keys.
     *
     * @param array<string|int, mixed> $data
     */
    public function fromArray(array $data): void
    {
        $this->config = $this->readonlies + $data + $this->config;
    }

    /**
     * @return array<string|int, mixed>
     */
    public function toArray(): array
    {
        return $this->config;
    }

    /**
     * Drop every directive except read-only ones that still hold their read-only value.
     *
     * Compared with strict equality on matching keys rather than array_intersect_assoc(),
     * which stringifies values and would treat any two array-valued directives as equal.
     */
    public function clear(): void
    {
        $restore = [];
        foreach ($this->readonlies as $key => $value) {
            if (\array_key_exists($key, $this->config) && $this->config[$key] === $value) {
                $restore[$key] = $value;
            }
        }
        $this->config = $restore;
    }

    /**
     * Drop request-specific directives at a worker request boundary, keeping read-only ones
     * and anything named in $preserveKeys.
     *
     * The key 'modules' preserves every `modules.*` directive rather than a directive of that
     * literal name, because module configuration is loaded once per process.
     *
     * @param array<int, string> $preserveKeys
     */
    public function resetWorkerState(array $preserveKeys = []): void
    {
        $preserve = [];

        foreach ($this->readonlies as $key => $dummy) {
            if (isset($this->config[$key])) {
                $preserve[$key] = $this->config[$key];
            }
        }

        foreach ($preserveKeys as $key) {
            if ($key === 'modules') {
                foreach ($this->config as $configKey => $configValue) {
                    if (str_starts_with((string) $configKey, 'modules.')) {
                        $preserve[$configKey] = $configValue;
                    }
                }
            } elseif (isset($this->config[$key])) {
                $preserve[$key] = $this->config[$key];
            }
        }

        $this->config = $preserve;
    }

    /**
     * Direct reference to the directive array, for the static facade's own compatibility
     * surface. Not part of the repository's contract: reach for the accessors instead.
     *
     * @return array<string|int, mixed>
     * @internal
     */
    public function &directives(): array
    {
        return $this->config;
    }

    /**
     * @throws ConfigurationException
     */
    private function toStringOrFail(string|int $name, mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }
        if (\is_scalar($value)) {
            return (string) $value;
        }

        throw new ConfigurationException(\sprintf('Config directive "%s" is not convertible to string, got %s.', $name, get_debug_type($value)));
    }
}
