<?php
namespace Quiote\Config;

use Quiote\Config\Util\DOM\XmlConfigDomDocument;
use Quiote\Exception\ConfigurationException;

/**
 * FactoryConfigHandler allows you to specify which factory implementation
 * the system will use.
 *
 * Migrated to IArrayConfigHandler (phase 2, second handler after
 * SettingConfigHandler). The factory
 * ordering/startup-sequence/must_implement logic in
 * getFactoryDefinitions() is pure PHP with no XML-specific content at
 * all -- it was never really "config", just code that happened to live
 * inside a DOM-reading method. The canonical array is exactly the
 * per-factory `class`/`params` pairs actually declared in the source
 * (XML, PHP, or YAML):
 *   [
 *     'validation_manager' => ['class' => 'Some\Class', 'params' => [...]],
 *     'response' => ['class' => '...', 'params' => [...]],
 *     // one entry per <factory-name> child element the XML configuration
 *     // (or, for a PHP/YAML file, top-level key) actually declares.
 *   ]
 * Only factories getFactoryDefinitions() marks 'required' => true are
 * looked for; a PHP-array/YAML factories file is simply this same map
 * written directly, e.g. `return ['database_manager' => ['class' => ...,
 * 'params' => [...]], ...];`.
 * @since      1.0.0
 * @version    1.0.0
 */
class FactoryConfigHandler extends XmlConfigHandler implements IArrayConfigHandler
{
	const XML_NAMESPACE = 'http://quiote.dev/quiote/config/parts/factories/1.1';

	/**
	 * The fixed factory ordering/startup-sequence definition. Order
	 * matters (database manager before storage/user, etc.) and is
	 * unrelated to config format -- see class docs. A bare string value
	 * (rather than name => info) is a "call startup() now" marker for the
	 * factory named by that string, interleaved with the declarations.
	 * @return array<int|string, string|array{required: bool, var: string|null, must_implement: array<int, string>}>
	 */
	private function getFactoryDefinitions(): array
	{
		return [
			// Validation manager remains a required factory (middleware replaces filters)
			'validation_manager' => [
				'required' => true,
				'var' => null,
				'must_implement' => [],
			],
			// Response factory info (global response instance)
			'response' => [
				'required' => true,
				'var' => null,
				'must_implement' => [],
			],
			// Order: database manager must be instantiated (and startup run) BEFORE storage & user.
			'database_manager' => [
				'required' => true,
				'var' => 'databaseManager',
				'must_implement' => [],
			],
			'database_manager', // startup()
			'translation_manager' => [
				'required' => Config::getBool('core.use_translation', false),
				'var' => 'translationManager',
				'must_implement' => [],
			],
			'routing' => [
				'required' => true,
				'var' => 'routing',
				'must_implement' => [],
			],
			// Ensure a legacy request object exists for templates/views and worker-mode recreation
			'request' => [
				'required' => true,
				'var' => 'request',
				'must_implement' => [],
			],
			'controller' => [
				'required' => true,
				'var' => 'controller',
				'must_implement' => [],
			],
			'storage' => [
				'required' => true,
				'var' => 'storage',
				'must_implement' => [],
			],
			'storage', // startup()
			'user' => [
				'required' => true,
				'var' => 'user',
				'must_implement' => [],
			],
			'translation_manager', // startup()
			'user', // startup()
			'routing', // startup()
			'controller', // startup()
		];
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
	 * @return array<string, array{class: string|null, params: array<mixed>}>
	 */
	public function toCanonicalArray(XmlConfigDomDocument $document): array
	{
		// set up our default namespace
		$document->setDefaultNamespace(self::XML_NAMESPACE, 'factories');

		$data = [];
		$factories = $this->getFactoryDefinitions();

		foreach ($document->getConfigurationElements() as $configuration) {
			foreach ($factories as $factory => $info) {
				if (!is_string($factory) || !is_array($info)) {
					// startup-sequence markers are stored under bare integer keys
					// (see getFactoryDefinitions()) and carry no XML element to read.
					continue;
				}

				if ($info['required']) {
					$element = $configuration->getChild($factory);
					if ($element !== null) {
						$data[$factory] ??= ['class' => null, 'params' => []];
						$data[$factory]['class'] = $element->getAttribute('class', $data[$factory]['class']);
						$data[$factory]['params'] = $element->getQuioteParameters($data[$factory]['params']);
					}
				}
			}
		}

		return $data;
	}

	/**
	 * Actionable, factory-specific guidance appended to the generic "missing
	 * or incomplete entry" error. Some factories (translation_manager) only
	 * become 'required' when a core.use_* flag is flipped on, and a freshly
	 * scaffolded app's factories file has no entry for them at all -- the
	 * generic message alone gives no hint that a new entry needs adding, or
	 * what class to point it at.
	 */
	private function missingFactoryHint(string $factory): ?string
	{
		return match ($factory) {
			'translation_manager' => 'This entry becomes required once "core.use_translation" is enabled. '
				. 'Add a translation_manager factory pointing at Quiote\\Translation\\TranslationManager, e.g. in factories.php: '
				. "'translation_manager' => ['class' => \\Quiote\\Translation\\TranslationManager::class, 'params' => []].",
			default => null,
		};
	}

	/**
	 * @param array<string, array{class: string|null, params: array<mixed>}> $config
	 */
	public function executeArray(array $config, ?string $sourceRef = null): string
	{
		$factories = $this->getFactoryDefinitions();
		$data = $config;

		$code = [];
		$shutdownSequence = [];

		foreach ($factories as $factory => $info) {
			if (is_array($info)) {
				$required = $info['required'];

				if (!$required) {
					continue;
				}

				if (!isset($data[$factory]) || $data[$factory]['class'] === null) {
					$error = 'Configuration file "%s" has missing or incomplete entry "%s"';
					$error = sprintf($error, $sourceRef, $factory);
					if ($hint = $this->missingFactoryHint((string) $factory)) {
						$error .= ' ' . $hint;
					}
					throw new ConfigurationException($error);
				}

				$class = $data[$factory]['class'];
				if (!class_exists($class) && !interface_exists($class)) {
					$error = 'Configuration file "%s" specifies unknown class "%s" for entry "%s"';
					$error = sprintf($error, $sourceRef, $class, $factory);
					throw new ConfigurationException($error);
				}

				$rc = new \ReflectionClass($class);
				foreach ($info['must_implement'] as $interface) {
					if (!$rc->implementsInterface($interface)) {
						$error = 'Class "%s" for entry "%s" does not implement interface "%s" in configuration file "%s"';
						$error = sprintf($error, $data[$factory]['class'], $factory, $interface, $sourceRef);
						throw new ConfigurationException($error);
					}
				}

				if ($info['var'] !== null) {
					// we have to make an instance
					$code[] = sprintf(
						'$this->%1$s = new %2$s();' . "\n" . '$this->%1$s->initialize($this, %3$s);',
						$info['var'],
						$data[$factory]['class'],
						var_export($data[$factory]['params'], true)
					);
					// Capture factory info immediately for worker-mode lazy recreation safety (now for all var-based factories)
					$code[] = sprintf(
						'$this->%1$sFactoryInfo = [\'class\' => %2$s, \'parameters\' => %3$s];',
						$info['var'],
						var_export($data[$factory]['class'], true),
						var_export($data[$factory]['params'], true)
					);
				} else {
					// it's a factory info
					$code[] = sprintf(
						'$this->factories[%1$s] = %2$s;',
						var_export($factory, true),
						var_export([
							'class' => $data[$factory]['class'],
							'parameters' => $data[$factory]['params'],
						], true)
					);
					// Provide explicit factory info array for compatibility with createInstanceFor() callers
					$code[] = sprintf(
						'$this->factories[%1$s][\'factory_info\'] = %2$s;',
						var_export($factory, true),
						var_export([
							'class' => $data[$factory]['class'],
							'parameters' => $data[$factory]['params'],
						], true)
					);
				}

				// No close conditional block needed
			} else {
				// Handle startup calls
				$definition = $factories[$info] ?? null;
				if (!is_array($definition)) {
					// no matching factory definition for this startup marker; nothing to start up
					continue;
				}
				$varName = $definition['var'];
				$required = $definition['required'];

				if ($required) {
					$code[] = sprintf('$this->%s->startup();', $varName);
					array_unshift($shutdownSequence, sprintf('$this->%s', $varName));
				}
			}
		}

		// Set the shutdown sequence
		$code[] = sprintf('$this->shutdownSequence = [%s];', implode(",\n", $shutdownSequence));

		return $this->generate($code, $sourceRef);
	}
}

?>
