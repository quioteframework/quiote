<?php
namespace Quiote\Config;

use Quiote\Config\Format\Xml\ElementPositionIndex;
use Quiote\Config\Schema\Rule;
use Quiote\Config\Util\DOM\XmlConfigDomDocument;
use Quiote\Exception\ConfigurationException;
use Quiote\Translation\QuioteLocale;

/**
 * TranslationConfigHandler allows you to define translator implementations
 * for different domains.
 *
 * Migrated to IArrayConfigHandler (phase 2). Canonical schema:
 *   ['default_domain' => string, 'default_locale' => string|null, 'default_timezone' => string|null,
 *    'locales' => ['identifier' => ['name' => ..., 'params' => [...], 'fallback' => ..., 'ldml_file' => ...]],
 *    'translators' => ['domain' => ['msg'|'num'|'cur'|'date' => ['class' => ..., 'filters' => [...], 'params' => [...]]]]]
 * getFilters()/getTranslators() are DOM-walking helpers used only by
 * toCanonicalArray(); the translator-class existence check (a pure
 * function of the finished canonical array) moved to executeArray().
 * @since      1.0.0
 * @version    1.0.0
 */
class TranslationConfigHandler extends XmlConfigHandler implements IArrayConfigHandler, ISchemaAwareConfigHandler, IPositionAwareConfigHandler
{
	const XML_NAMESPACE = 'http://quiote.dev/quiote/config/parts/translation/1.1';

	public function schema(): Rule
	{
		$translatorEntry = Rule::struct([
			'class' => Rule::phpClass(nullable: true),
			'filters' => Rule::listOf(Rule::mixed()),
			'params' => Rule::mixed(),
		], required: ['class', 'filters', 'params']);

		$domain = Rule::struct([
			'msg' => $translatorEntry,
			'num' => $translatorEntry,
			'cur' => $translatorEntry,
			'date' => $translatorEntry,
		]);

		$locale = Rule::struct([
			'name' => Rule::string(),
			'params' => Rule::mixed(),
			'fallback' => Rule::string(nullable: true),
			'ldml_file' => Rule::string(nullable: true),
		], required: ['name', 'params', 'fallback', 'ldml_file']);

		return Rule::struct([
			'default_domain' => Rule::string(),
			'default_locale' => Rule::string(nullable: true),
			'default_timezone' => Rule::string(nullable: true),
			'locales' => Rule::dictOf($locale),
			'translators' => Rule::dictOf($domain),
		], required: ['default_domain', 'default_locale', 'default_timezone', 'locales', 'translators']);
	}

	/**
	 * @throws     \Quiote\Exception\ParseException If a requested configuration file is
	 *                                        improperly formatted.
	 * @since      1.0.0
	 */
	public function execute(XmlConfigDomDocument $document): string
	{
		return $this->executeArray($this->toCanonicalArray($document), $document->documentURI);
	}

	/**
	 * @return array{
	 *     default_domain: string,
	 *     default_locale: ?string,
	 *     default_timezone: ?string,
	 *     locales: array<string, array{name: string, params: mixed, fallback: ?string, ldml_file: ?string}>,
	 *     translators: array<string, mixed>,
	 * }
	 */
	public function toCanonicalArray(XmlConfigDomDocument $document): array
	{
		// set up our default namespace
		$document->setDefaultNamespace(self::XML_NAMESPACE, 'translation');

		$translatorData = [];
		$localeData = [];

		$defaultDomain = '';
		$defaultLocale = null;
		$defaultTimeZone = null;

		foreach ($document->getConfigurationElements() as $cfg) {

			$availableLocales = $cfg->getChild('available_locales');
			// TODO: is this really optional? according to the schema: yes...
			if ($availableLocales !== null) {
				$defaultLocale = $availableLocales->getAttribute('default_locale', $defaultLocale) ?? $defaultLocale;
				$defaultTimeZone = $availableLocales->getAttribute('default_timezone', $defaultTimeZone) ?? $defaultTimeZone;
				foreach ($availableLocales as $locale) {
					$name = $locale->getAttribute('identifier') ?? '';
					if (!isset($localeData[$name])) {
						$localeData[$name] = ['name' => $name, 'params' => [], 'fallback' => null, 'ldml_file' => null];
					}
					$localeData[$name]['params'] = $locale->getQuioteParameters($localeData[$name]['params']);
					$localeData[$name]['fallback'] = $locale->getAttribute('fallback', $localeData[$name]['fallback']) ?? $localeData[$name]['fallback'];
					$localeData[$name]['ldml_file'] = $locale->getAttribute('ldml_file', $localeData[$name]['ldml_file']) ?? $localeData[$name]['ldml_file'];
				}
			}

			$translators = $cfg->getChild('translators');
			if ($translators !== null) {
				$defaultDomain = $translators->getAttribute('default_domain', $defaultDomain) ?? $defaultDomain;
				$this->getTranslators($translators, $translatorData);
			}
		}

		return [
			'default_domain' => $defaultDomain,
			'default_locale' => $defaultLocale,
			'default_timezone' => $defaultTimeZone,
			'locales' => $localeData,
			'translators' => $translatorData,
		];
	}

	/**
	 * Positions are only tracked for "locales" -- a flat, single-level
	 * walk. "translators" builds a recursive, potentially deeply-nested
	 * domain hierarchy via getTranslators(); mirroring that faithfully for
	 * position purposes isn't attempted here (translation.xml also has
	 * legacy-upgrade <transformation> stylesheets configured by default, so
	 * positions come back empty in practice anyway -- see
	 * TranslationConfigHandlerPositionTest).
	 * @return array{
	 *     data: array{
	 *         default_domain: string,
	 *         default_locale: ?string,
	 *         default_timezone: ?string,
	 *         locales: array<string, array{name: string, params: mixed, fallback: ?string, ldml_file: ?string}>,
	 *         translators: array<string, mixed>,
	 *     },
	 *     positions: array<string, array{file: string, line: int}>,
	 * }
	 */
	public function toCanonicalArrayWithPositions(XmlConfigDomDocument $document, ElementPositionIndex $positions): array
	{
		$document->setDefaultNamespace(self::XML_NAMESPACE, 'translation');

		$data = $this->toCanonicalArray($document);
		$elementPositions = [];

		foreach ($document->getConfigurationElements() as $cfg) {
			$availableLocales = $cfg->getChild('available_locales');
			if ($availableLocales !== null) {
				foreach ($availableLocales as $locale) {
					$name = $locale->getAttribute('identifier') ?? '';
					$position = $positions->forElement($locale);
					if ($position !== null) {
						$elementPositions["locales.{$name}.name"] = $position;
					}
				}
			}
		}

		return ['data' => $data, 'positions' => $elementPositions];
	}

	/**
	 * @param array{
	 *     default_domain?: string,
	 *     default_locale?: ?string,
	 *     default_timezone?: ?string,
	 *     locales?: array<string, array{name: string, params: mixed, fallback: ?string, ldml_file: ?string}>,
	 *     translators?: array<string, mixed>,
	 * } $config
	 */
	public function executeArray(array $config, ?string $sourceRef = null): string
	{
		$defaultDomain = $config['default_domain'] ?? '';
		$defaultLocale = $config['default_locale'] ?? null;
		$defaultTimeZone = $config['default_timezone'] ?? null;
		$localeData = $config['locales'] ?? [];
		$translatorData = $config['translators'] ?? [];

		$data = [];

		$data[] = sprintf('$this->defaultDomain = %s;', var_export($defaultDomain, true));
		$data[] = sprintf('$this->defaultLocaleIdentifier = %s;', var_export($defaultLocale, true));
		$data[] = sprintf('$this->defaultTimeZone = %s;', var_export($defaultTimeZone, true));

		foreach ($localeData as $locale) {
			// TODO: fallback stuff

			$data[] = sprintf('$this->availableConfigLocales[%s] = array(\'identifier\' => %s, \'identifierData\' => %s, \'parameters\' => %s);', var_export($locale['name'], true), var_export($locale['name'], true), var_export(QuioteLocale::parseLocaleIdentifier($locale['name']), true), var_export($locale['params'], true));
		}

		foreach ($translatorData as $domain => $translator) {
			if (!is_array($translator)) {
				continue;
			}
			foreach (['msg', 'num', 'cur', 'date'] as $type) {
				$entry = $translator[$type] ?? null;
				if (!is_array($entry) || !isset($entry['class']) || !is_string($entry['class'])) {
					continue;
				}
				$class = $entry['class'];

				if (!class_exists($class)) {
					throw new ConfigurationException(sprintf('The Translator or Formatter class "%s" for domain "%s" could not be found.', $class, $domain));
				}
				$data[] = implode("\n", [
					sprintf('$this->translators[%s][%s] = new %s();', var_export($domain, true), var_export($type, true), $class),
					sprintf('$this->translators[%s][%s]->initialize($this->getContext(), %s);', var_export($domain, true), var_export($type, true), var_export($entry['params'] ?? [], true)),
					sprintf('$this->translatorFilters[%s][%s] = %s;', var_export($domain, true), var_export($type, true), var_export($entry['filters'] ?? [], true)),
				]);
			}
		}

		return $this->generate($data, $sourceRef);
	}

	/**
	 * Builds a list of filters for a translator.
	 * @param      \Quiote\Config\Util\DOM\XmlConfigDomElement $translator The Translator node.
	 * @return     array<int, string|array<int, string>> An array of filter definitions.
	 * @since      1.0.0
	 */
	protected function getFilters($translator)
	{
		$filters = [];
		if ($translator->has('filters')) {
			// get() only ever selects element nodes, and registerNodeClass()
			// guarantees those are always XmlConfigDomElement, never a vanilla DOMNode.
			foreach ($translator->get('filters') as $filter) {
				/** @var \Quiote\Config\Util\DOM\XmlConfigDomElement $filter */
				$func = explode('::', (string) $filter->getValue());
				if (count($func) != 2) {
					$func = $func[0];
				}
				if (!is_callable($func)) {
					throw new ConfigurationException('Non-existant or uncallable filter function "' . $filter->getValue() . '" specified.');
				}
				$filters[] = $func;
			}
		}
		return $filters;
	}

	/**
	 * Build a list of translators.
	 * @param      iterable<int, \Quiote\Config\Util\DOM\XmlConfigDomElement> $translators The translators container.
	 * @param      array<string, mixed> $data The destination data array.
	 * @param      ?string                $parent The name of the parent domain.
	 * @return     void
	 * @since      1.0.0
	 */
	protected function getTranslators($translators, &$data, $parent = null)
	{
		static $defaultData = [
			'msg'  => ['class' => null, 'filters' => [], 'params' => []],
			'num'  => ['class' => \Quiote\Translation\QuioteNumberFormatter::class, 'filters' => [], 'params' => []],
			'cur'  => ['class' => \Quiote\Translation\CurrencyFormatter::class, 'filters' => [], 'params' => []],
			'date' => ['class' => \Quiote\Translation\DateFormatter::class, 'filters' => [], 'params' => []],
		];

		foreach ($translators as $translator) {
			$domain = $translator->getAttribute('domain') ?? '';
			if ($parent) {
				$domain = $parent . '.' . $domain;
			}
			if (!isset($data[$domain])) {
				if (!$parent) {
					$data[$domain] = $defaultData;
				} else {
					$data[$domain] = [];
				}
			}

			$domainData =& $data[$domain];

			foreach (['msg' => 'message_translator', 'num' => 'number_formatter', 'cur' => 'currency_formatter', 'date' => 'date_formatter'] as $type => $nodeName) {
				$node = $translator->getChild($nodeName);
				if ($node !== null) {
					if (!isset($domainData[$type])) {
						$domainData[$type] = $defaultData[$type];
					}

					if ($node->hasAttribute('translation_domain')) {
						$domainData[$type]['params']['translation_domain'] = $node->getAttribute('translation_domain');
					}
					$domainData[$type]['class'] = $node->getAttribute('class', $domainData[$type]['class']) ?? $domainData[$type]['class'];
					$domainData[$type]['params'] = $node->getQuioteParameters($domainData[$type]['params']);
					$domainData[$type]['filters'] = array_merge($domainData[$type]['filters'], $this->getFilters($node));
				}
			}

			if ($translator->has('translators')) {
				// get() only ever selects element nodes, and registerNodeClass()
				// guarantees those are always XmlConfigDomElement, never a vanilla DOMNode.
				/** @var iterable<int, \Quiote\Config\Util\DOM\XmlConfigDomElement> $childTranslators */
				$childTranslators = $translator->get('translators');
				$this->getTranslators($childTranslators, $data, $domain);
			}
		}
	}
}

?>
