<?php
namespace Quiote\Config;

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
class TranslationConfigHandler extends XmlConfigHandler implements IArrayConfigHandler
{
	const XML_NAMESPACE = 'http://quiote.dev/quiote/config/parts/translation/1.1';

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
	 * @return array<string, mixed>
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

			if ($cfg->hasChild('available_locales')) {
				$availableLocales = $cfg->getChild('available_locales');
				// TODO: is this really optional? according to the schema: yes...
				$defaultLocale = $availableLocales->getAttribute('default_locale', $defaultLocale);
				$defaultTimeZone = $availableLocales->getAttribute('default_timezone', $defaultTimeZone);
				foreach ($availableLocales as $locale) {
					$name = $locale->getAttribute('identifier');
					if (!isset($localeData[$name])) {
						$localeData[$name] = ['name' => $name, 'params' => [], 'fallback' => null, 'ldml_file' => null];
					}
					$localeData[$name]['params'] = $locale->getQuioteParameters($localeData[$name]['params']);
					$localeData[$name]['fallback'] = $locale->getAttribute('fallback', $localeData[$name]['fallback']);
					$localeData[$name]['ldml_file'] = $locale->getAttribute('ldml_file', $localeData[$name]['ldml_file']);
				}
			}

			if ($cfg->hasChild('translators')) {
				$translators = $cfg->getChild('translators');
				$defaultDomain = $translators->getAttribute('default_domain', $defaultDomain);
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
	 * @param array<string, mixed> $config
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
			foreach (['msg', 'num', 'cur', 'date'] as $type) {
				if (isset($translator[$type]['class'])) {
					if (!class_exists($translator[$type]['class'])) {
						throw new ConfigurationException(sprintf('The Translator or Formatter class "%s" for domain "%s" could not be found.', $translator[$type]['class'], $domain));
					}
					$data[] = implode("\n", [
						sprintf('$this->translators[%s][%s] = new %s();', var_export($domain, true), var_export($type, true), $translator[$type]['class']),
						sprintf('$this->translators[%s][%s]->initialize($this->getContext(), %s);', var_export($domain, true), var_export($type, true), var_export($translator[$type]['params'], true)),
						sprintf('$this->translatorFilters[%s][%s] = %s;', var_export($domain, true), var_export($type, true), var_export($translator[$type]['filters'], true)),
					]);
				}
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
	 * @param      array<string, mixed>   $data The destination data array.
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
			$domain = $translator->getAttribute('domain');
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
				if ($translator->hasChild($nodeName)) {
					$node = $translator->getChild($nodeName);
					if (!isset($domainData[$type])) {
						$domainData[$type] = $defaultData[$type];
					}

					if ($node->hasAttribute('translation_domain')) {
						$domainData[$type]['params']['translation_domain'] = $node->getAttribute('translation_domain');
					}
					$domainData[$type]['class'] = $node->getAttribute('class', $domainData[$type]['class']);
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
