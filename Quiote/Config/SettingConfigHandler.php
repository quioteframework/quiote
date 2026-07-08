<?php
namespace Quiote\Config;

use Quiote\Config\Util\DOM\XmlConfigDomDocument;
use Quiote\Exception\ParseException;

/**
 * SettingConfigHandler handles the settings.xml file.
 *
 * Pilot migration (phase 2): the
 * actual compilation logic (executeArray()) now consumes a plain array
 * instead of walking the DOM directly, so the exact same logic compiles a
 * settings.php or settings.yaml file too (via
 * Quiote\Config\Format\FormatDriverRegistry::forHandler()), not just XML.
 *
 * The canonical array shape is a flat, dot-keyed map -- exactly what
 * execute() used to build inline before generating code from it:
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
 * @since      1.0.0
 * @version    1.0.0
 */
class SettingConfigHandler extends XmlConfigHandler implements IArrayConfigHandler
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
	public function execute(XmlConfigDomDocument $document): string
	{
		return $this->executeArray($this->toCanonicalArray($document), $document->documentURI);
	}

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

	public function executeArray(array $config, ?string $sourceRef = null): string
	{
		$code = 'Quiote\\Config\\Config::fromArray(' . var_export($config, true) . ');';
		return $this->generate($code, $sourceRef);
	}
}

?>
