<?php

namespace Quiote\Controller;

use Quiote\Exception\ConfigurationException;

/**
 * What the compiled `output_types` configuration declares, as data.
 *
 * The compiled form used to be statements `require`d inside {@see Controller::initialize()},
 * constructing each {@see OutputType} and assigning it into `$this->outputTypes` from a scope it had
 * no business reaching. It is a declaration now, for the same reasons the compiled factories and
 * databases configurations are -- see {@see \Quiote\Config\Factory\FactoryDefinitions}.
 *
 * @since      4.0.0
 */
final readonly class OutputTypeDefinitions
{
    /**
     * @param      array<string, array{parameters: array<string, mixed>, renderers: array<string, array<string, mixed>>, defaultRenderer: ?string, layouts: array<string, array<string, mixed>>, defaultLayout: ?string, exceptionTemplate: ?string}> $outputTypes
     *             Keyed by output-type name, in declaration order.
     * @param      ?string $default The output type answered when none is named. Null is legal: a
     *             configuration may declare types without electing one, and
     *             {@see Controller::getOutputType()} falls back on its own terms.
     */
    public function __construct(
        public array $outputTypes,
        public ?string $default,
    ) {}

    /**
     * Read a compiled declaration, rejecting anything malformed.
     *
     * @param      mixed $compiled Whatever the compiled file returned.
     * @throws     ConfigurationException When $compiled is not a declaration this version
     *             understands -- most likely a cache compiled by an earlier one.
     * @since      4.0.0
     */
    public static function fromCompiled(mixed $compiled, string $source = 'the compiled output_types cache'): self
    {
        if (!is_array($compiled) || !array_key_exists('outputTypes', $compiled)
            || !array_key_exists('default', $compiled)) {
            throw new ConfigurationException(sprintf(
                '%s did not return an output-type declaration. It was most likely compiled by an '
                . 'earlier version of Quiote; clear the configuration cache.',
                ucfirst($source),
            ));
        }

        if (!is_array($compiled['outputTypes'])
            || ($compiled['default'] !== null && !is_string($compiled['default']))) {
            throw new ConfigurationException(
                ucfirst($source) . ' returned a malformed output-type declaration; clear the '
                . 'configuration cache.',
            );
        }

        $outputTypes = [];
        foreach ($compiled['outputTypes'] as $name => $declaration) {
            if (!is_string($name) || !is_array($declaration)) {
                throw new ConfigurationException(
                    ucfirst($source) . ' contains an unnamed or malformed output type; clear the '
                    . 'configuration cache.',
                );
            }

            $outputTypes[$name] = [
                'parameters' => self::namedValues($declaration['parameters'] ?? []),
                'renderers' => self::namedGroups($declaration['renderers'] ?? []),
                'defaultRenderer' => self::optionalName($declaration['defaultRenderer'] ?? null),
                'layouts' => self::namedGroups($declaration['layouts'] ?? []),
                'defaultLayout' => self::optionalName($declaration['defaultLayout'] ?? null),
                'exceptionTemplate' => self::optionalName($declaration['exceptionTemplate'] ?? null),
            ];
        }

        if ($compiled['default'] !== null && !isset($outputTypes[$compiled['default']])) {
            throw new ConfigurationException(sprintf(
                '%s names "%s" as the default output type, which it does not declare.',
                ucfirst($source),
                $compiled['default'],
            ));
        }

        return new self($outputTypes, $compiled['default']);
    }

    /**
     * Narrow a declared sub-array to the string-keyed shape {@see OutputType::initialize()} takes.
     *
     * @return     array<string, mixed>
     * @since      4.0.0
     */
    private static function namedValues(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $named = [];
        foreach ($value as $key => $entry) {
            $named[(string) $key] = $entry;
        }

        return $named;
    }

    /**
     * Narrow a declared map-of-maps -- renderers, layouts -- to the shape
     * {@see OutputType::initialize()} takes. An entry that is not itself a map is dropped rather
     * than passed on as a scalar the consumer would then have to guard against.
     *
     * @return     array<string, array<string, mixed>>
     * @since      4.0.0
     */
    private static function namedGroups(mixed $value): array
    {
        $groups = [];
        foreach (self::namedValues($value) as $name => $entry) {
            if (is_array($entry)) {
                $groups[$name] = self::namedValues($entry);
            }
        }

        return $groups;
    }

    /**
     * A declared name, or null. Anything that is not a string is treated as absent: these feed
     * "which renderer/layout/template by name" lookups, and a non-string could never match one.
     *
     * @since      4.0.0
     */
    private static function optionalName(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
