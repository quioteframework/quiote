<?php
namespace Quiote\Config;

use Quiote\Config\Format\Xml\ElementPositionIndex;
use Quiote\Config\Schema\Rule;
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
class ModuleConfigHandler extends XmlConfigHandler implements IArrayConfigHandler, ISchemaAwareConfigHandler, IPositionAwareConfigHandler
{
	const XML_NAMESPACE = 'http://quiote.dev/quiote/config/parts/module/1.1';

	/**
	 * "settings" is an open, dynamically-keyed flat map (fully-prefixed
	 * setting names -> mixed value, exactly like SettingConfigHandler's own
	 * shape) -- only its container structure is fixed, not its key names.
	 */
	public function schema(): Rule
	{
		return Rule::struct([
			'enabled' => Rule::bool(),
			'settings' => Rule::dictOf(Rule::mixed()),
		], required: ['enabled', 'settings']);
	}

	/**
	 * @throws     \Quiote\Exception\ParseException If a requested configuration file is
	 *                                        improperly formatted.
	 * @since      1.0.0
	 */
	public function execute(XmlConfigDomDocument $document): mixed
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
				/** @var XmlConfigDomElement $setting */
				$localPrefix = $prefix;

				// let's see if this buddy has a <settings> parent with valuable information
				/** @var XmlConfigDomElement $settingParent */
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

	/**
	 * @return array{data: array{enabled: bool, settings: array<string, mixed>}, positions: array<string, array{file: string, line: int}>}
	 */
	public function toCanonicalArrayWithPositions(XmlConfigDomDocument $document, ElementPositionIndex $positions): array
	{
		$document->setDefaultNamespace(self::XML_NAMESPACE, 'module');

		$prefix = 'modules.${moduleName}.';
		$enabled = false;
		$settings = [];
		$elementPositions = [];

		foreach ($document->getConfigurationElements() as $configuration) {
			$module = $configuration->getChild('module');
			if (!$module) {
				continue;
			}

			$enabled = (bool) Toolkit::literalize($module->getAttribute('enabled'));
			$modulePosition = $positions->forElement($module);
			if ($modulePosition !== null) {
				$elementPositions['enabled'] = $modulePosition;
			}

			foreach ($module->get('settings') as $setting) {
				/** @var XmlConfigDomElement $setting */
				$localPrefix = $prefix;

				/** @var XmlConfigDomElement $settingParent */
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

				$settingPosition = $positions->forElement($setting);
				if ($settingPosition !== null) {
					$elementPositions["settings.{$settingName}"] = $settingPosition;
				}
			}
		}

		return ['data' => ['enabled' => $enabled, 'settings' => $settings], 'positions' => $elementPositions];
	}

	/**
	 * @param array{enabled?: bool, settings?: array<string, mixed>} $config
	 */
	public function executeArray(array $config, ?string $sourceRef = null): mixed
	{
		return [
			'enabled' => $config['enabled'] ?? false,
			'settings' => $config['settings'] ?? [],
		];
	}

	/**
	 * Apply a module's compiled declaration for the module it belongs to.
	 *
	 * The module name is the caller's to supply: it is not in the declaration, and it cannot be -- the
	 * wildcard module.xml handler compiles once per module but produces the same shape for all of
	 * them, and `${moduleName}` in a setting *key* is what turns it into
	 * `modules.bulletins.some_setting`. {@see \Quiote\Controller\Controller::initializeModule()} knows
	 * the name, so it passes it in.
	 *
	 * `${moduleName}` in a setting *value* is a different mechanism and is deliberately left
	 * unexpanded here: those sit alongside `${actionName}`/`${viewName}`, which are only knowable when
	 * an action or view is being resolved.
	 *
	 * @param      mixed $declaration The declaration {@see executeArray()} compiles.
	 * @param      string $moduleName The module the declaration belongs to; lowercased for the keys.
	 * @param      string $sourceRef The module config file, for diagnostics.
	 * @return     void
	 * @throws     \Quiote\Exception\ConfigurationException If the declaration is not the compiled shape.
	 * @since      4.0.0
	 */
	public static function applyDeclaration(mixed $declaration, string $moduleName, string $sourceRef): void
	{
		if (!is_array($declaration) || !array_key_exists('enabled', $declaration)) {
			throw new \Quiote\Exception\ConfigurationException(sprintf(
				'The compiled module declaration from "%s" must be an array with "enabled" and "settings" keys, got %s.',
				$sourceRef,
				get_debug_type($declaration)
			));
		}

		$variables = ['moduleName' => strtolower($moduleName)];

		Config::set(
			Toolkit::expandVariables('modules.${moduleName}.enabled', $variables),
			(bool) $declaration['enabled'],
			true,
			true
		);

		$settings = $declaration['settings'] ?? [];
		if (!is_array($settings)) {
			throw new \Quiote\Exception\ConfigurationException(sprintf(
				'The "settings" key of the compiled module declaration from "%s" must be an array, got %s.',
				$sourceRef,
				get_debug_type($settings)
			));
		}

		if ($settings === []) {
			return;
		}

		$expanded = [];
		foreach ($settings as $key => $value) {
			$expanded[Toolkit::expandVariables((string) $key, $variables)] = $value;
		}

		Config::fromArray($expanded);
	}
}

?>
