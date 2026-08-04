<?php

namespace Quiote\Config\Factory;

use Quiote\Exception\ConfigurationException;

/**
 * The components a {@see ComponentInstaller} built, keyed by role.
 *
 * The point of handing these back rather than writing them into their owner is that the owner then
 * assigns them itself, by name and with a declared type:
 *
 * ```php
 * $this->routing = $installed->need('routing', Routing::class);
 * ```
 *
 * That line is checked statically and breaks at compile time if the property is renamed or retyped.
 * The compiled configuration it ultimately came from names only the role, and cannot reach a
 * property at all.
 *
 * @since      4.0.0
 */
final readonly class InstalledComponents
{
    /**
     * @param      array<string, object> $components Keyed by role.
     */
    public function __construct(private array $components) {}

    /**
     * The component for a required role.
     *
     * @template   T of object
     * @param      class-string<T> $expected
     * @return     T
     * @throws     ConfigurationException When the role was not built, or was built as something
     *             else. Both mean the configuration and this code disagree about what a role is,
     *             which is worth saying rather than discovering through a type error on assignment.
     * @since      4.0.0
     */
    public function need(string $role, string $expected): object
    {
        $component = $this->components[$role] ?? null;

        if ($component === null) {
            throw new ConfigurationException(sprintf(
                'The factories configuration declares no "%s". It is required.',
                $role,
            ));
        }

        if (!$component instanceof $expected) {
            throw new ConfigurationException(sprintf(
                'The factories configuration builds "%s" as %s, which is not a %s.',
                $role,
                $component::class,
                $expected,
            ));
        }

        return $component;
    }

    /**
     * The component for an optional role, or null when the configuration declares none.
     *
     * For roles a context legitimately does without: no translation manager when translation is
     * off, no database manager when `core.use_database` is false.
     *
     * @template   T of object
     * @param      class-string<T> $expected
     * @return     ?T
     * @throws     ConfigurationException When the role was built as something else. Absent is fine;
     *             present and wrong is still a configuration error.
     * @since      4.0.0
     */
    public function optional(string $role, string $expected): ?object
    {
        if (!isset($this->components[$role])) {
            return null;
        }

        return $this->need($role, $expected);
    }

    /**
     * Whether a role was built.
     *
     * @since      4.0.0
     */
    public function has(string $role): bool
    {
        return isset($this->components[$role]);
    }

    /**
     * The roles that were built.
     *
     * @return     array<int, string>
     * @since      4.0.0
     */
    public function roles(): array
    {
        return array_keys($this->components);
    }
}
