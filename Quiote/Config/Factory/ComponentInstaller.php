<?php

namespace Quiote\Config\Factory;

use Quiote\Context;
use Quiote\Exception\ConfigurationException;

/**
 * Carries out a {@see FactoryDefinitions} operation list.
 *
 * This is the behaviour that used to be *generated* as PHP statements and `include`d into the
 * context: construct, initialize, start up, in a specific interleaved order. Written once here, it
 * is ordinary code that can be read and tested, rather than a string assembled by a config handler
 * and executed against the context's private scope.
 *
 * The order in the operation list is honoured exactly, including the interleaving of construction
 * and startup: the database manager's startup() has to run before the user is built, because the
 * user may read through it.
 *
 * @since      4.0.0
 */
final class ComponentInstaller
{
    public function __construct(private readonly Context $context) {}

    /**
     * Build and start up every component the definitions declare.
     *
     * @throws     ConfigurationException When a declared class is missing, is not a context
     *             component, or a startup names a role that was never built.
     * @since      4.0.0
     */
    public function install(FactoryDefinitions $definitions): InstalledComponents
    {
        /** @var array<string, object> $components */
        $components = [];

        foreach ($definitions->operations as $operation) {
            $role = $operation['role'];

            if ($operation['op'] === FactoryDefinitions::OP_STARTUP) {
                $this->startUp($components[$role] ?? null, $role);
                continue;
            }

            $components[$role] = $this->build(
                $role,
                $operation['class'] ?? '',
                $operation['parameters'] ?? [],
            );
        }

        return new InstalledComponents($components);
    }

    /**
     * Construct one component and run its initialize().
     *
     * @param      array<string, mixed> $parameters
     * @throws     ConfigurationException
     * @since      4.0.0
     */
    private function build(string $role, string $class, array $parameters): object
    {
        if (!class_exists($class)) {
            throw new ConfigurationException(sprintf(
                'The factories configuration declares class "%s" for "%s", which does not exist. '
                . 'If it was renamed, clear the configuration cache.',
                $class,
                $role,
            ));
        }

        $component = new $class();

        // Duck-typed rather than requiring ContextComponentInterface: the components predate that
        // interface and not all of them implement it yet (Controller, for one), while all of them do
        // have the two methods. Requiring the interface here would refuse configurations that have
        // always worked.
        if (!method_exists($component, 'initialize')) {
            throw new ConfigurationException(sprintf(
                'Class "%s", declared for "%s", has no initialize() method, so it cannot be '
                . 'configured against the context.',
                $class,
                $role,
            ));
        }

        $component->initialize($this->context, $parameters);

        return $component;
    }

    /**
     * Run a built component's startup().
     *
     * @throws     ConfigurationException When the role was never built. That is a broken operation
     *             list rather than a configuration mistake a user made, so it is worth failing on
     *             instead of skipping: a component that never starts up is a subtly broken request,
     *             not an obviously broken boot.
     * @since      4.0.0
     */
    private function startUp(?object $component, string $role): void
    {
        if ($component === null) {
            throw new ConfigurationException(sprintf(
                'The factories configuration starts up "%s" before building it.',
                $role,
            ));
        }

        if (method_exists($component, 'startup')) {
            $component->startup();
        }
    }
}
