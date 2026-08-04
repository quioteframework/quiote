<?php

namespace Quiote\Config\Factory;

use Quiote\Exception\ConfigurationException;

/**
 * What the compiled `factories` configuration says, as data.
 *
 * The compiled factory configuration used to be executable PHP that was `include`d inside
 * {@see \Quiote\Context::initialize()}, where it assigned straight into the context's properties:
 * `$this->user = new SecurityUser();`, `$this->userFactoryInfo = [...]`. Included code takes on the
 * scope it is included into, so a generated file had full private access to the context and nothing
 * anywhere declared which properties it was allowed to touch. Renaming or retyping one of them
 * broke a cached file at runtime, in the boot path, with an error naming the property rather than
 * the stale cache.
 *
 * So the compiled form is now a declaration instead: an ordered list of operations naming *roles*,
 * which something else carries out. A role is part of the configuration contract; a property name
 * is an implementation detail the compiled file no longer knows.
 *
 * @since      4.0.0
 */
final readonly class FactoryDefinitions
{
    public const OP_BUILD = 'build';
    public const OP_STARTUP = 'startup';

    /**
     * @param      array<int, array{op: string, role: string, class?: string, parameters?: array<string, mixed>}> $operations
     *             Ordered. Interleaving matters: a role's startup() may have to run before a later
     *             role is even constructed, which is why this is one ordered list rather than a
     *             list of components plus a list of startups.
     * @param      array<string, array{class: string, parameters: array<string, mixed>}> $factories
     *             Roles that are not built eagerly -- the caller instantiates these on demand.
     * @param      array<int, string> $shutdownOrder Roles to shut down, in order.
     */
    public function __construct(
        public array $operations,
        public array $factories,
        public array $shutdownOrder,
    ) {}

    /**
     * Read a compiled definition set, rejecting anything malformed.
     *
     * The validation is not ceremony: this reads a *generated* file, and the failure it guards
     * against is a cache compiled by a different version of the framework. Saying so plainly beats
     * a type error thrown from somewhere in the boot path.
     *
     * @param      mixed $compiled Whatever the compiled file returned.
     * @throws     ConfigurationException When $compiled is not a definition set this version
     *             understands.
     * @since      4.0.0
     */
    public static function fromCompiled(mixed $compiled, string $source = 'the compiled factories cache'): self
    {
        if (!is_array($compiled) || !isset($compiled['operations'], $compiled['factories'], $compiled['shutdownOrder'])) {
            throw new ConfigurationException(sprintf(
                '%s did not return a factory definition set. It was most likely compiled by an '
                . 'earlier version of Quiote; clear the configuration cache.',
                ucfirst($source),
            ));
        }

        if (!is_array($compiled['operations']) || !is_array($compiled['factories']) || !is_array($compiled['shutdownOrder'])) {
            throw new ConfigurationException(
                ucfirst($source) . ' returned a malformed factory definition set; clear the configuration cache.',
            );
        }

        $operations = [];
        foreach ($compiled['operations'] as $operation) {
            if (!is_array($operation) || !isset($operation['op'], $operation['role'])
                || !is_string($operation['op']) || !is_string($operation['role'])) {
                throw new ConfigurationException(
                    ucfirst($source) . ' contains a factory operation with no op/role; clear the configuration cache.',
                );
            }
            if ($operation['op'] === self::OP_BUILD
                && (!isset($operation['class']) || !is_string($operation['class']))) {
                throw new ConfigurationException(sprintf(
                    '%s declares a build of "%s" with no class; clear the configuration cache.',
                    ucfirst($source),
                    $operation['role'],
                ));
            }
            /** @var array{op: string, role: string, class?: string, parameters?: array<string, mixed>} $operation */
            $operations[] = $operation;
        }

        $factories = [];
        foreach ($compiled['factories'] as $role => $info) {
            if (!is_string($role) || !is_array($info) || !isset($info['class']) || !is_string($info['class'])) {
                throw new ConfigurationException(
                    ucfirst($source) . ' contains a factory slot with no class; clear the configuration cache.',
                );
            }
            $parameters = $info['parameters'] ?? [];
            $factories[$role] = [
                'class' => $info['class'],
                'parameters' => is_array($parameters) ? $parameters : [],
            ];
        }

        $shutdownOrder = [];
        foreach ($compiled['shutdownOrder'] as $role) {
            if (is_string($role)) {
                $shutdownOrder[] = $role;
            }
        }

        return new self($operations, $factories, $shutdownOrder);
    }

    /**
     * The class and parameters declared for a role that is built eagerly.
     *
     * This is what the lazy worker-mode recreation paths rebuild from, so it has to be answerable
     * for a role whose instance has since been dropped.
     *
     * @return     ?array{class: string, parameters: array<string, mixed>}
     * @since      4.0.0
     */
    public function buildInfo(string $role): ?array
    {
        foreach ($this->operations as $operation) {
            if ($operation['op'] === self::OP_BUILD && $operation['role'] === $role) {
                return [
                    'class' => $operation['class'] ?? '',
                    'parameters' => $operation['parameters'] ?? [],
                ];
            }
        }

        return null;
    }

    /**
     * The roles built eagerly, in construction order.
     *
     * @return     array<int, string>
     * @since      4.0.0
     */
    public function builtRoles(): array
    {
        $roles = [];
        foreach ($this->operations as $operation) {
            if ($operation['op'] === self::OP_BUILD) {
                $roles[] = $operation['role'];
            }
        }

        return $roles;
    }
}
