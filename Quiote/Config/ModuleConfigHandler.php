<?php
namespace Quiote\Config;

use Quiote\Config\Util\DOM\XmlConfigDomDocument;
use Quiote\Config\Util\DOM\XmlConfigDomElement;
use Quiote\Util\Toolkit;

/**
 * ModuleConfigHandler reads module configuration files to determine the
 * status of a module.
 *
 * Migrated to IArrayConfigHandler (phase 2). Canonical schema:
 *   ['enabled' => bool, 'settings' => ['fully_prefixed_setting_name' => value]]
 * Setting keys are already fully prefixed in the canonical array, exactly
 * as the original DOM-walking code built them: 'modules.${moduleName}.'
 * (a literal template string, `${moduleName}` expanded at runtime -- not
 * module-specific data) by default, or whatever a <settings prefix="...">
 * wrapper specified instead. A PHP/YAML module file therefore writes keys
 * like 'modules.${moduleName}.some_setting' (or a fully custom prefix)
 * directly, same as the array XML already produces.
 * @since      1.0.0
 * @version    1.0.0
 */
class ModuleConfigHandler extends XmlConfigHandler implements IArrayConfigHandler
{
	const XML_NAMESPACE = 'http://quiote.dev/quiote/config/parts/module/1.1';

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
	 * @return     array{enabled: bool, settings: array<string, mixed>}
	 */
	public function toCanonicalArray(XmlConfigDomDocument $document): array
	{
		// set up our default namespace
		$document->setDefaultNamespace(self::XML_NAMESPACE, 'module');

		$prefix = 'modules.${moduleName}.';
		$enabled = false;
		$settings = [];

		// loop over <configuration> elements
		foreach ($document->getConfigurationElements() as $configuration) {
			$module = $configuration->getChild('module');
			if (!$module) {
				continue;
			}

			// enabled flag is treated separately
			$enabled = (bool) Toolkit::literalize($module->getAttribute('enabled'));

			// loop over <setting> elements; there can be many of them
			foreach ($module->get('settings') as $setting) {
				// The get() call above only ever selects element nodes, and
				// registerNodeClass() guarantees those are always XmlConfigDomElement,
				// never a vanilla DOMNode.
				/** @var XmlConfigDomElement<int, XmlConfigDomElement> $setting */
				$localPrefix = $prefix;

				// let's see if this buddy has a <settings> parent with valuable information
				/** @var XmlConfigDomElement<int, XmlConfigDomElement> $settingParent */
				$settingParent = $setting->parentNode;
				if ($settingParent->localName == 'settings') {
					if ($settingParent->hasAttribute('prefix')) {
						$localPrefix = $settingParent->getAttribute('prefix');
					}
				}

				$settingName = $localPrefix . $setting->getAttribute('name');
				if ($setting->hasQuioteParameters()) {
					$settings[$settingName] = $setting->getQuioteParameters();
				} else {
					$settings[$settingName] = Toolkit::literalize($setting->getValue());
				}
			}
		}

		return ['enabled' => $enabled, 'settings' => $settings];
	}

	public function executeArray(array $config, ?string $sourceRef = null): string
	{
		$enabled = $config['enabled'] ?? false;
		$data = $config['settings'] ?? [];
		$prefix = 'modules.${moduleName}.';

		$code = [];
		$code[] = '$lcModuleName = strtolower($moduleName);';
		$code[] = 'Quiote\Config\Config::set(Quiote\Util\Toolkit::expandVariables(' . var_export($prefix . 'enabled', true) . ', array(\'moduleName\' => $lcModuleName)), ' . var_export($enabled, true) . ', true, true);';
		if (count($data)) {
			$code[] = '$moduleConfig = ' . var_export($data, true) . ';';
			$code[] = '$moduleConfigKeys = array_keys($moduleConfig);';
			$code[] = 'foreach($moduleConfigKeys as &$value) $value = Quiote\Util\Toolkit::expandVariables($value, array(\'moduleName\' => $lcModuleName));';
			$code[] = '$moduleConfig = array_combine($moduleConfigKeys, $moduleConfig);';
			$code[] = 'Quiote\Config\Config::fromArray($moduleConfig);';
		}

		return $this->generate($code, $sourceRef);
	}
}

?>
