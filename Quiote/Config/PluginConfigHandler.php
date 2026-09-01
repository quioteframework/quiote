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
 * An `enabled` written as a `%env(...)%` placeholder cannot be decided while the file is being
 * compiled, so such an entry survives compilation as a `{class, enabled}` pair and
 * {@see EnvPlaceholder} turns the placeholder into the bool when the artifact is loaded. That is what
 * lets a deployment turn a plugin on by setting a variable and restarting, with the same compiled
 * cache.
 *
 * Also records each declared class's file in {@see \Quiote\Plugin\PluginConfigRegistry}, so
 * introspection (`quiote plugins:list`) can report where a plugin came from -- the app's own
 * config or a specific module's, and which file format -- without the flat `plugins` config key
 * itself needing to carry that.
 *
 * Canonical schema: list<array{class: string, enabled: bool|string}>, in
 * document order, where a string `enabled` is an unresolved placeholder.
 * @since      1.0.0
 */
class PluginConfigHandler extends XmlConfigHandler implements IArrayConfigHandler, ISchemaAwareConfigHandler, IPositionAwareConfigHandler, IDeclarationConfigHandler
{
	const XML_NAMESPACE = 'http://quiote.dev/quiote/config/parts/plugins/1.1';

	/**
	 * "enabled" is not required: hand-authored PHP/YAML may omit it,
	 * defaulting to true, matching the XSD's own default. It is a bool or the
	 * string form of a `%env(...)%` placeholder that is not resolved yet.
	 */
	public function schema(): Rule
	{
		return Rule::listOf(Rule::struct([
			'class' => Rule::phpClass(),
			'enabled' => Rule::oneOf(Rule::bool(), Rule::string()),
		], required: ['class']));
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
	 * The `enabled` attribute as a bool, or unchanged when it is a `%env(...)%`
	 * placeholder whose value only exists at load time.
	 *
	 * A placeholder keeps its case: environment variable names are
	 * case-sensitive, and nothing here has to decide what the value means yet.
	 */
	private static function enabledFrom(?string $attribute): bool|string
	{
		$value = $attribute ?? 'true';

		return EnvPlaceholder::contains($value) ? $value : (bool) Toolkit::literalize($value);
	}

	/**
	 * @return list<array{class: string, enabled: bool|string}>
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
				$plugins[] = [
					// XSD requires "class"; the (string) cast reflects that guarantee to PHPStan.
					'class' => (string) $plugin->getAttribute('class'),
					'enabled' => self::enabledFrom($plugin->getAttribute('enabled')),
				];
			}
		}

		return $plugins;
	}

	/**
	 * @return array{data: list<array{class: string, enabled: bool|string}>, positions: array<string, array{file: string, line: int}>}
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
				$plugins[] = [
					'class' => (string) $plugin->getAttribute('class'),
					'enabled' => self::enabledFrom($plugin->getAttribute('enabled')),
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
	 * Compiles the canonical array down to what the artifact holds: a class name
	 * per enabled plugin, and a `{class, enabled}` pair for one whose `enabled`
	 * is a `%env(...)%` placeholder, which nothing can decide yet.
	 *
	 * @param list<array{class: string, enabled?: bool|string}> $config Hand-authored
	 *        PHP/YAML sources may omit `enabled` (defaults to true), matching
	 *        the XSD's own default.
	 * @return list<string|array{class: string, enabled: string}>
	 */
	public function executeArray(array $config, ?string $sourceRef = null): mixed
	{
		$declared = [];
		foreach ($config as $plugin) {
			$enabled = $plugin['enabled'] ?? true;

			if (is_string($enabled) && EnvPlaceholder::contains($enabled)) {
				$declared[] = ['class' => $plugin['class'], 'enabled' => $enabled];
				continue;
			}

			// A hand-authored PHP/YAML source can write "yes"/"off" as a string where XML would have
			// been literalized on the way in, so the same literals mean the same thing in every format.
			if (is_string($enabled) ? (bool) Toolkit::literalize($enabled) : $enabled) {
				$declared[] = $plugin['class'];
			}
		}

		return $declared;
	}

	/**
	 * Append the declared plugin classes to the `plugins` config key.
	 *
	 * An entry is either a class name -- a plugin whose `enabled` was decided at compile time -- or a
	 * `{class, enabled}` pair whose `enabled` a `%env(...)%` placeholder has just been resolved into
	 * by {@see EnvPlaceholder}, and which is dropped here if the environment said no.
	 *
	 * @param      mixed $declaration The classes and deferred entries, in declared order, that
	 *                    {@see executeArray()} compiles.
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
		foreach ($declaration as $index => $entry) {
			if (is_array($entry)) {
				$class = self::deferredClass($entry, $index, $sourceRef);
				if ($class !== null) {
					$declared[] = $class;
				}
				continue;
			}

			if (!is_string($entry)) {
				throw new ConfigurationException(sprintf(
					'Entry %s of the compiled plugins declaration from "%s" must be a class name string, got %s.',
					var_export($index, true),
					$sourceRef,
					get_debug_type($entry)
				));
			}
			$declared[] = $entry;
		}

		\Quiote\Plugin\PluginConfigRegistry::contribute($declared, $sourceRef);
		Config::set('plugins', self::merge($declared, Config::getArray('plugins', [])), true);
	}

	/**
	 * The class name of a `{class, enabled}` entry whose environment placeholder resolved to true, or
	 * null when it resolved to false.
	 *
	 * The value has been through the environment by the time it gets here, so anything other than a
	 * bool means a variable holds something that is not a boolean literal -- which is a deployment
	 * mistake worth naming rather than quietly reading as truthy.
	 *
	 * Neither message repeats what the environment answered, only its type: the value reaching here
	 * came out of a variable, an exception is logged and rendered, and a misconfiguration pointing
	 * this at a credential is exactly the case that produces a wrong value.
	 *
	 * @param      array<array-key, mixed> $entry
	 * @throws     ConfigurationException If the entry is not shaped as expected, or `enabled` did not
	 *                                   resolve to a bool.
	 */
	private static function deferredClass(array $entry, int|string $index, string $sourceRef): ?string
	{
		$class = $entry['class'] ?? null;
		if (!is_string($class) || !array_key_exists('enabled', $entry)) {
			throw new ConfigurationException(sprintf(
				'Entry %s of the compiled plugins declaration from "%s" must be a class name string or a '
				. '{class, enabled} pair, got keys [%s].',
				var_export($index, true),
				$sourceRef,
				implode(', ', array_map(strval(...), array_keys($entry)))
			));
		}

		$enabled = $entry['enabled'];
		if (!is_bool($enabled)) {
			throw new ConfigurationException(sprintf(
				'Plugin "%s" in "%s" has its "enabled" read from the environment, which answered a %s. '
				. 'The variable must hold a boolean literal: true, false, on, off, yes or no.',
				$class,
				$sourceRef,
				get_debug_type($enabled)
			));
		}

		return $enabled ? $class : null;
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
