<?php
namespace Quiote\Config;

use Quiote\Config\Format\Xml\ElementPositionIndex;
use Quiote\Config\Schema\Rule;
use Quiote\Config\Util\DOM\XmlConfigDomDocument;
use Quiote\Exception\ConfigurationException;
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
 * `%core.module_dir%/<name>/Config/plugins.xml`). Each compiled artifact returns just the classes it
 * declares; {@see apply()} reads the `plugins` key's current value and appends only classes not
 * already present, so declared order across files is preserved and the first occurrence of a class
 * (across all contributing files, applied in bootstrap order) wins if the same class is listed more
 * than once.
 *
 * Canonical schema: list<array{class: string, enabled: bool}>, in document
 * order.
 * @since      1.0.0
 */
class PluginConfigHandler extends XmlConfigHandler implements IArrayConfigHandler, ISchemaAwareConfigHandler, IPositionAwareConfigHandler, IDeclarationConfigHandler
{
	const XML_NAMESPACE = 'http://quiote.dev/quiote/config/parts/plugins/1.1';

	/**
	 * "enabled" is not required: hand-authored PHP/YAML may omit it,
	 * defaulting to true, matching the XSD's own default.
	 */
	public function schema(): Rule
	{
		return Rule::listOf(Rule::struct([
			'class' => Rule::phpClass(),
			'enabled' => Rule::bool(),
		], required: ['class']));
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
	 * @return array{data: list<array{class: string, enabled: bool}>, positions: array<string, array{file: string, line: int}>}
	 */
	public function toCanonicalArrayWithPositions(XmlConfigDomDocument $document, ElementPositionIndex $positions): array
	{
		$document->setDefaultNamespace(self::XML_NAMESPACE, 'plugins');

		$plugins = [];
		$elementPositions = [];
		$index = 0;
		foreach ($document->getConfigurationElements() as $configuration) {
			if (!$configuration->has('plugins')) {
				continue;
			}

			foreach ($configuration->get('plugins') as $plugin) {
				$enabledAttr = strtolower((string) $plugin->getAttribute('enabled', 'true'));
				$plugins[] = [
					'class' => (string) $plugin->getAttribute('class'),
					'enabled' => (bool) Toolkit::literalize($enabledAttr),
				];

				$position = $positions->forElement($plugin);
				if ($position !== null) {
					$elementPositions["[$index].class"] = $position;
					if ($plugin->hasAttribute('enabled')) {
						$elementPositions["[$index].enabled"] = $position;
					}
				}
				$index++;
			}
		}

		return ['data' => $plugins, 'positions' => $elementPositions];
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

		return $this->generate('return ' . var_export($declared, true) . ';', $sourceRef);
	}

	/**
	 * Append the declared plugin classes to the `plugins` config key.
	 *
	 * @param      mixed $declaration The enabled classes, in declared order, that {@see executeArray()}
	 *                    compiles.
	 * @since      4.0.0
	 */
	public function apply(mixed $declaration, string $sourceRef): void
	{
		if (!is_array($declaration)) {
			throw new ConfigurationException(sprintf(
				'The compiled plugins declaration from "%s" must be a list of class names, got %s.',
				$sourceRef,
				get_debug_type($declaration)
			));
		}

		$declared = [];
		foreach ($declaration as $index => $class) {
			if (!is_string($class)) {
				throw new ConfigurationException(sprintf(
					'Entry %s of the compiled plugins declaration from "%s" must be a class name string, got %s.',
					var_export($index, true),
					$sourceRef,
					get_debug_type($class)
				));
			}
			$declared[] = $class;
		}

		Config::set('plugins', self::merge($declared, Config::getArray('plugins', [])), true);
	}

	/**
	 * Merge declared plugin classes into the classes already registered, appending only what is not
	 * there yet.
	 *
	 * Declared order is preserved and the first occurrence of a class wins, across every contributing
	 * file: the app's own `plugins.*` is applied before any module's, so an app listing the same class
	 * as a module keeps the app's position.
	 *
	 * An already-registered entry may be a class name or a `['class' => ...]` array (a
	 * {@see \Quiote\Plugin\PluginInterface} instance placed there directly by an app is left alone
	 * too), so comparison happens on the class name while the existing entry is kept as it stands.
	 *
	 * @param      list<string> $declared Class names to append, in declared order.
	 * @param      array<int|string, mixed> $existing The current `plugins` config value.
	 * @return     list<mixed> The merged list.
	 * @since      4.0.0
	 */
	public static function merge(array $declared, array $existing): array
	{
		$merged = array_values($existing);
		$seen = [];
		foreach ($merged as $plugin) {
			$name = self::classNameOf($plugin);
			if ($name !== null) {
				$seen[$name] = true;
			}
		}

		foreach ($declared as $class) {
			if (isset($seen[$class])) {
				continue;
			}
			$merged[] = $class;
			$seen[$class] = true;
		}

		return $merged;
	}

	/**
	 * The class name an existing `plugins` entry stands for, or null when it is something this merge
	 * cannot compare (in which case it is carried over untouched rather than deduplicated).
	 *
	 * @since      4.0.0
	 */
	private static function classNameOf(mixed $plugin): ?string
	{
		if (is_string($plugin)) {
			return $plugin;
		}
		if (is_array($plugin) && isset($plugin['class']) && is_string($plugin['class'])) {
			return $plugin['class'];
		}
		if (is_object($plugin)) {
			return $plugin::class;
		}

		return null;
	}
}
