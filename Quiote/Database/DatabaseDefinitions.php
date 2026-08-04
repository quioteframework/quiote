<?php

namespace Quiote\Database;

use Quiote\Exception\ConfigurationException;

/**
 * What the compiled `databases` configuration declares, as data.
 *
 * The compiled form used to be statements `require`d inside
 * {@see DatabaseManager::initialize()}, assigning into `$this->databases` and
 * `$this->defaultDatabaseName` from a scope it had no business reaching. It is a declaration now,
 * for the same reasons the compiled factories configuration is -- see
 * {@see \Quiote\Config\Factory\FactoryDefinitions}.
 *
 * @since      4.0.0
 */
final readonly class DatabaseDefinitions
{
    /**
     * @param      array<string, array{class: class-string<Database>, parameters: array<string, mixed>}> $databases
     *             Keyed by connection name, in declaration order.
     * @param      string $default The connection answered when none is named.
     */
    public function __construct(
        public array $databases,
        public string $default,
    ) {}

    /**
     * Read a compiled declaration, rejecting anything malformed.
     *
     * @param      mixed $compiled Whatever the compiled file returned.
     * @throws     ConfigurationException When $compiled is not a declaration this version
     *             understands -- most likely a cache compiled by an earlier one.
     * @since      4.0.0
     */
    public static function fromCompiled(mixed $compiled, string $source = 'the compiled databases cache'): self
    {
        if (!is_array($compiled) || !isset($compiled['databases'], $compiled['default'])) {
            throw new ConfigurationException(sprintf(
                '%s did not return a database declaration. It was most likely compiled by an '
                . 'earlier version of Quiote; clear the configuration cache.',
                ucfirst($source),
            ));
        }

        if (!is_array($compiled['databases']) || !is_string($compiled['default'])) {
            throw new ConfigurationException(
                ucfirst($source) . ' returned a malformed database declaration; clear the configuration cache.',
            );
        }

        $databases = [];
        foreach ($compiled['databases'] as $name => $definition) {
            if (!is_string($name) || !is_array($definition)
                || !isset($definition['class']) || !is_string($definition['class'])) {
                throw new ConfigurationException(
                    ucfirst($source) . ' declares a database connection with no class; clear the '
                    . 'configuration cache.',
                );
            }

            if (!class_exists($definition['class'])) {
                throw new ConfigurationException(sprintf(
                    '%s declares class "%s" for database "%s", which does not exist. If it was '
                    . 'renamed, clear the configuration cache.',
                    ucfirst($source),
                    $definition['class'],
                    $name,
                ));
            }

            if (!is_a($definition['class'], Database::class, true)) {
                throw new ConfigurationException(sprintf(
                    '%s declares class "%s" for database "%s", which is not a %s.',
                    ucfirst($source),
                    $definition['class'],
                    $name,
                    Database::class,
                ));
            }

            $parameters = $definition['parameters'] ?? [];
            $named = [];
            foreach (is_array($parameters) ? $parameters : [] as $key => $value) {
                if (!is_string($key)) {
                    // Database::initialize() takes named parameters. A positional one would be
                    // dropped by any coercion here, so it is refused instead.
                    throw new ConfigurationException(sprintf(
                        '%s declares database "%s" with a non-string parameter key (%s). Database '
                        . 'parameters are named.',
                        ucfirst($source),
                        $name,
                        var_export($key, true),
                    ));
                }
                $named[$key] = $value;
            }

            $databases[$name] = [
                'class' => $definition['class'],
                'parameters' => $named,
            ];
        }

        if (!isset($databases[$compiled['default']])) {
            throw new ConfigurationException(sprintf(
                '%s names "%s" as the default database, which it does not declare.',
                ucfirst($source),
                $compiled['default'],
            ));
        }

        return new self($databases, $compiled['default']);
    }
}
