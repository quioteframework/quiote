<?php

namespace Quiote\Translation;

use Quiote\Exception\ConfigurationException;

/**
 * What the compiled `translation` configuration declares, as data.
 *
 * The compiled form used to be statements `include`d inside
 * {@see TranslationManager::initialize()}, assigning into its properties and calling
 * `$this->getContext()` on it. It is a declaration now, for the same reasons the compiled factories,
 * databases and output_types configurations are -- see
 * {@see \Quiote\Config\Factory\FactoryDefinitions}.
 *
 * The parsed locale identifiers are precomputed at compile time and carried here, which is why
 * `identifierData` is part of the declared shape rather than something the manager derives.
 *
 * @since      4.0.0
 */
final readonly class TranslationDefinitions
{
    /**
     * @param      string $defaultDomain The domain used when a caller names none.
     * @param      ?string $defaultLocale The locale identifier to start in. Null is legal here and
     *             rejected later by the manager, which has a better message for it than this class
     *             could: translation is configured but unusable without one.
     * @param      ?string $defaultTimeZone Null means "fall back to PHP's default".
     * @param      array<string, array{identifier: string, identifierData: array{language: ?string, script: ?string, territory: ?string, variant: ?string, options: array<string, string>, locale_str: ?string, option_str: ?string}, parameters: array<string, mixed>}> $locales
     *             Keyed by locale identifier, in declaration order. The parsed identifier is
     *             precomputed at compile time by {@see QuioteLocale::parseLocaleIdentifier()}.
     * @param      array<string, array<string, array{class: class-string<ITranslator>, parameters: array<string, mixed>, filters: array<int, callable>}>> $translators
     *             Keyed by domain, then by type ('msg', 'num', 'cur', 'date').
     */
    public function __construct(
        public string $defaultDomain,
        public ?string $defaultLocale,
        public ?string $defaultTimeZone,
        public array $locales,
        public array $translators,
    ) {}

    /**
     * Read a compiled declaration, rejecting anything malformed.
     *
     * @param      mixed $compiled Whatever the compiled file returned.
     * @throws     ConfigurationException When $compiled is not a declaration this version
     *             understands -- most likely a cache compiled by an earlier one.
     * @since      4.0.0
     */
    public static function fromCompiled(mixed $compiled, string $source = 'the compiled translation cache'): self
    {
        foreach (['defaultDomain', 'defaultLocale', 'defaultTimeZone', 'locales', 'translators'] as $key) {
            if (!is_array($compiled) || !array_key_exists($key, $compiled)) {
                throw new ConfigurationException(sprintf(
                    '%s did not return a translation declaration. It was most likely compiled by an '
                    . 'earlier version of Quiote; clear the configuration cache.',
                    ucfirst($source),
                ));
            }
        }

        if (!is_array($compiled['locales']) || !is_array($compiled['translators'])) {
            throw new ConfigurationException(
                ucfirst($source) . ' returned a malformed translation declaration; clear the '
                . 'configuration cache.',
            );
        }

        return new self(
            is_string($compiled['defaultDomain']) ? $compiled['defaultDomain'] : '',
            is_string($compiled['defaultLocale']) ? $compiled['defaultLocale'] : null,
            is_string($compiled['defaultTimeZone']) ? $compiled['defaultTimeZone'] : null,
            self::readLocales($compiled['locales'], $source),
            self::readTranslators($compiled['translators'], $source),
        );
    }

    /**
     * @param      array<mixed> $declared
     * @return     array<string, array{identifier: string, identifierData: array{language: ?string, script: ?string, territory: ?string, variant: ?string, options: array<string, string>, locale_str: ?string, option_str: ?string}, parameters: array<string, mixed>}>
     * @throws     ConfigurationException
     * @since      4.0.0
     */
    private static function readLocales(array $declared, string $source): array
    {
        $locales = [];
        foreach ($declared as $name => $locale) {
            if (!is_string($name) || !is_array($locale) || !isset($locale['identifier'])
                || !is_string($locale['identifier'])) {
                throw new ConfigurationException(
                    ucfirst($source) . ' declares a locale with no identifier; clear the '
                    . 'configuration cache.',
                );
            }

            $locales[$name] = [
                'identifier' => $locale['identifier'],
                'identifierData' => self::readIdentifierData($locale['identifierData'] ?? [], $name, $source),
                'parameters' => self::named($locale['parameters'] ?? []),
            ];
        }

        return $locales;
    }

    /**
     * Read the precomputed parts of a locale identifier.
     *
     * Each part is checked because this comes out of a generated file: a cache compiled when
     * {@see QuioteLocale::parseLocaleIdentifier()} answered a different shape would otherwise feed
     * a half-populated array to every locale match in the process.
     *
     * @return     array{language: ?string, script: ?string, territory: ?string, variant: ?string, options: array<string, string>, locale_str: ?string, option_str: ?string}
     * @throws     ConfigurationException
     * @since      4.0.0
     */
    private static function readIdentifierData(mixed $declared, string $locale, string $source): array
    {
        if (!is_array($declared)) {
            throw new ConfigurationException(sprintf(
                '%s declares locale "%s" with no parsed identifier; clear the configuration cache.',
                ucfirst($source),
                $locale,
            ));
        }

        $options = [];
        foreach (is_array($declared['options'] ?? null) ? $declared['options'] : [] as $key => $value) {
            if (is_string($value)) {
                $options[(string) $key] = $value;
            }
        }

        return [
            'language' => self::optionalString($declared['language'] ?? null),
            'script' => self::optionalString($declared['script'] ?? null),
            'territory' => self::optionalString($declared['territory'] ?? null),
            'variant' => self::optionalString($declared['variant'] ?? null),
            'options' => $options,
            'locale_str' => self::optionalString($declared['locale_str'] ?? null),
            'option_str' => self::optionalString($declared['option_str'] ?? null),
        ];
    }

    /**
     * @since      4.0.0
     */
    private static function optionalString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * @param      array<mixed> $declared
     * @return     array<string, array<string, array{class: class-string<ITranslator>, parameters: array<string, mixed>, filters: array<int, callable>}>>
     * @throws     ConfigurationException
     * @since      4.0.0
     */
    private static function readTranslators(array $declared, string $source): array
    {
        $translators = [];
        foreach ($declared as $domain => $types) {
            if (!is_string($domain) || !is_array($types)) {
                continue;
            }

            foreach ($types as $type => $entry) {
                if (!is_string($type) || !is_array($entry) || !isset($entry['class'])
                    || !is_string($entry['class'])) {
                    throw new ConfigurationException(sprintf(
                        '%s declares a "%s" translator for domain "%s" with no class; clear the '
                        . 'configuration cache.',
                        ucfirst($source),
                        is_string($type) ? $type : get_debug_type($type),
                        $domain,
                    ));
                }

                $class = $entry['class'];
                if (!class_exists($class)) {
                    throw new ConfigurationException(sprintf(
                        '%s declares class "%s" as the "%s" translator for domain "%s", which does '
                        . 'not exist. If it was renamed, clear the configuration cache.',
                        ucfirst($source),
                        $class,
                        $type,
                        $domain,
                    ));
                }

                if (!is_a($class, ITranslator::class, true)) {
                    throw new ConfigurationException(sprintf(
                        'The Translator or Formatter class "%s" for domain "%s" is not a %s.',
                        $class,
                        $domain,
                        ITranslator::class,
                    ));
                }

                $translators[$domain][$type] = [
                    'class' => $class,
                    'parameters' => self::named($entry['parameters'] ?? []),
                    'filters' => self::readFilters($entry['filters'] ?? [], $domain, $type, $source),
                ];
            }
        }

        return $translators;
    }

    /**
     * Read a translator's filter list.
     *
     * Filters are applied with call_user_func(), so a declared filter that is not callable would
     * otherwise fail deep inside a translate() call, naming the filter machinery rather than the
     * configuration entry that is actually wrong.
     *
     * @return     array<int, callable>
     * @throws     ConfigurationException
     * @since      4.0.0
     */
    private static function readFilters(mixed $declared, string $domain, string $type, string $source): array
    {
        $filters = [];
        foreach (is_array($declared) ? $declared : [] as $filter) {
            if (!is_callable($filter)) {
                throw new ConfigurationException(sprintf(
                    '%s declares a filter for the "%s" translator of domain "%s" that is not '
                    . 'callable (%s).',
                    ucfirst($source),
                    $type,
                    $domain,
                    get_debug_type($filter),
                ));
            }
            $filters[] = $filter;
        }

        return $filters;
    }

    /**
     * Narrow a declared sub-array to the string-keyed shape its consumer takes.
     *
     * @return     array<string, mixed>
     * @since      4.0.0
     */
    private static function named(mixed $value): array
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
}
