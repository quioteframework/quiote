<?php
namespace Quiote\Config;

use Quiote\Config\Util\DOM\XmlConfigDomDocument;
use Quiote\Exception\ParseException;

/**
 * SettingConfigHandler handles the settings.xml file.
 *
 * The compilation logic (executeArray()) consumes a plain array rather than walking the DOM, so the
 * same logic compiles a settings.php or settings.yaml file (via
 * Quiote\Config\Format\FormatDriverRegistry::forHandler()), not just XML.
 *
 * The canonical array shape is a flat, dot-keyed map:
 *   'actions.{name}_module'          => string   (from <system_action name="..."><module>)
 *   'actions.{name}_action'          => string   (from <system_action name="..."><action>)
 *   '{prefix}{setting_name}'         => mixed    (prefix defaults to 'core.'; a <settings prefix="...">
 *                                                  wrapper overrides it for its children; the value is
 *                                                  either a scalar/nested array from <ae:parameters>, or
 *                                                  the setting's literal text value)
 *
 * A PHP-array or YAML settings file is simply this map written directly,
 * e.g. `return ['core.app_name' => 'Demo', 'core.debug' => true];` --
 * there is no XML-specific concept (system_actions/settings/prefix
 * wrappers) left to represent once you're at this shape.
 *
 * The compiled artifact returns that map; {@see apply()} is what feeds it to {@see Config}.
 * @since      1.0.0
 * @version    1.0.0
 */
class SettingConfigHandler extends XmlConfigHandler implements IArrayConfigHandler, IDeclarationConfigHandler
{
	const XML_NAMESPACE = 'http://quiote.dev/quiote/config/parts/settings/1.1';

	/**
	 * @throws     \Quiote\Exception\UnreadableException If a requested configuration
	 *                                             file does not exist or is not
	 *                                             readable.
	 * @throws     \Quiote\Exception\ParseException If a requested configuration file is
	 *                                        improperly formatted.
	 * @since      1.0.0
	 */
	public function execute(XmlConfigDomDocument $document): mixed
	{
		return $this->executeArray($this->toCanonicalArray($document), $document->documentURI);
	}

	/**
	 * Flattens the document's system actions and settings into the dot-keyed
	 * map described in this class's summary.
	 *
	 * Each system action contributes an `actions.{name}_module` and an
	 * `actions.{name}_action` entry. Each setting is keyed by its name behind a
	 * prefix — `core.` unless an enclosing `<settings prefix="...">` overrides
	 * it for its children — and takes either its nested parameters or its
	 * literalized text value.
	 *
	 * @throws ParseException if a system action is missing its `<module>` or
	 *                        `<action>` child.
	 */
	public function toCanonicalArray(XmlConfigDomDocument $document): array
	{
		// set up our default namespace
		$document->setDefaultNamespace(self::XML_NAMESPACE, 'settings');

		// init our data array
		$data = [];

		$prefix = 'core.';

		foreach ($document->getConfigurationElements() as $cfg) {
			// let's do our fancy work
			if ($cfg->has('system_actions')) {
				foreach ($cfg->get('system_actions') as $action) {
					$name = $action->getAttribute('name');
					$moduleElement = $action->getChild('module');
					$actionElement = $action->getChild('action');
					if ($moduleElement === null || $actionElement === null) {
						throw new ParseException(sprintf(
							'Configuration file "%s" has a system_action "%s" missing its required <module> or <action> child element',
							$document->documentURI,
							$name
						));
					}
					$data[sprintf('actions.%s_module', $name)] = $moduleElement->getValue();
					$data[sprintf('actions.%s_action', $name)] = $actionElement->getValue();
				}
			}

			// loop over <setting> elements; there can be many of them
			foreach ($cfg->get('settings') as $setting) {
				$localPrefix = $prefix;

				// let's see if this buddy has a <settings> parent with valuable information
				if ($setting->parentNode instanceof \DOMElement && $setting->parentNode->localName == 'settings') {
					if ($setting->parentNode->hasAttribute('prefix')) {
						$localPrefix = $setting->parentNode->getAttribute('prefix');
					}
				}

				$settingName = $localPrefix . $setting->getAttribute('name');
				if ($setting->hasQuioteParameters()) {
					$data[$settingName] = $setting->getQuioteParameters();
				} else {
					$data[$settingName] = $setting->getLiteralValue();
				}
			}
		}

		return $data;
	}

	/**
	 * Returns the flat setting map unchanged as the declaration to cache.
	 *
	 * The map is already the compiled artifact; {@see self::apply()} is what
	 * later feeds it into {@see Config}.
	 */
	public function executeArray(array $config, ?string $sourceRef = null): mixed
	{
		return $config;
	}

	/**
	 * Feed the declared settings into the configuration repository.
	 *
	 * @param      mixed $declaration The flat, dot-keyed map described in this class's summary.
	 * @since      4.0.0
	 */
	public function apply(mixed $declaration, string $sourceRef): void
	{
		if (!is_array($declaration)) {
			throw new \Quiote\Exception\ConfigurationException(sprintf(
				'The compiled settings declaration from "%s" must be an array of setting name => value, got %s.',
				$sourceRef,
				get_debug_type($declaration)
			));
		}

		Config::fromArray($declaration);
	}
}

?>
