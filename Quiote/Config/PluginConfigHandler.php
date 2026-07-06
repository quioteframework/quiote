<?php
namespace Quiote\Config;

use Quiote\Config\Util\DOM\XmlConfigDomDocument;
use Quiote\Util\Toolkit;

/**
 * PluginConfigHandler reads a `plugins.{xml,php,yaml,yml}` file -- the
 * correct, documented way to register plugins -- a flat, ordered
 * enable/disable list of plugin classes -- and appends the enabled ones to
 * the `plugins` config key that {@see \Quiote\Plugin\PluginManager::bootFromConfig()}
 * already reads. A `'plugins' => [...]` entry written directly into
 * `settings.*` happens to work too, since it shares the same key, but that's
 * an incidental consequence of the storage, not a supported interface --
 * don't document or rely on it. Per-plugin options are NOT part of this
 * schema; they stay in `settings.*`, contributed by the plugin itself via
 * {@see \Quiote\Plugin\PluginRegistrar::configDefault()}.
 *
 * Multiple plugin config files can contribute (the app's own
 * `%core.config_dir%/plugins.xml` plus any module's
 * `%core.module_dir%/<name>/Config/plugins.xml`) -- each compiled file reads
 * the `plugins` key's current value and appends only classes not already
 * present, so declared order across files is preserved and the first
 * occurrence of a class (across all contributing files, compiled in
 * bootstrap order) wins if the same class is listed more than once.
 *
 * Canonical schema: list<array{class: string, enabled: bool}>, in document
 * order.
 * @since      1.0.0
 */
class PluginConfigHandler extends XmlConfigHandler implements IArrayConfigHandler
{
	const XML_NAMESPACE = 'http://quiote.dev/quiote/config/parts/plugins/1.1';

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
	 * @return list<array{class: string, enabled: bool}>
	 */
	public function toCanonicalArray(XmlConfigDomDocument $document): array
	{
		// set up our default namespace
		$document->setDefaultNamespace(self::XML_NAMESPACE, 'plugins');

		$plugins = [];
		foreach ($document->getConfigurationElements() as $configuration) {
			if (!$configuration->has('plugins')) {
				continue;
			}

			foreach ($configuration->get('plugins') as $plugin) {
				$enabledAttr = strtolower((string) $plugin->getAttribute('enabled', 'true'));
				$plugins[] = [
					// XSD requires "class"; the (string) cast reflects that guarantee to PHPStan.
					'class' => (string) $plugin->getAttribute('class'),
					'enabled' => (bool) Toolkit::literalize($enabledAttr),
				];
			}
		}

		return $plugins;
	}

	/**
	 * @param list<array{class: string, enabled?: bool}> $config Hand-authored
	 *        PHP/YAML sources may omit `enabled` (defaults to true), matching
	 *        the XSD's own default.
	 */
	public function executeArray(array $config, ?string $sourceRef = null): string
	{
		$declared = array_values(array_map(
			static fn(array $plugin): string => $plugin['class'],
			array_filter($config, static fn(array $plugin): bool => $plugin['enabled'] ?? true),
		));

		$code = [];
		$code[] = '$quioteDeclaredPlugins = ' . var_export($declared, true) . ';';
		$code[] = '$quioteExistingPlugins = \Quiote\Config\Config::getArray(\'plugins\', []);';
		$code[] = '$quioteExistingPluginClasses = array_map(';
		$code[] = '    static fn($plugin) => is_array($plugin) ? ($plugin[\'class\'] ?? $plugin) : $plugin,';
		$code[] = '    $quioteExistingPlugins,';
		$code[] = ');';
		$code[] = 'foreach ($quioteDeclaredPlugins as $quioteDeclaredPlugin) {';
		$code[] = '    if (!in_array($quioteDeclaredPlugin, $quioteExistingPluginClasses, true)) {';
		$code[] = '        $quioteExistingPlugins[] = $quioteDeclaredPlugin;';
		$code[] = '        $quioteExistingPluginClasses[] = $quioteDeclaredPlugin;';
		$code[] = '    }';
		$code[] = '}';
		$code[] = '\Quiote\Config\Config::set(\'plugins\', $quioteExistingPlugins, true);';

		return $this->generate($code, $sourceRef);
	}
}
