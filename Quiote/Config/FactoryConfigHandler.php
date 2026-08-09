<?php
namespace Quiote\Config;

use Quiote\Config\Factory\FactoryDefinitions;
use Quiote\Config\Format\Xml\ElementPositionIndex;
use Quiote\Config\Schema\Rule;
use Quiote\Config\Util\DOM\XmlConfigDomDocument;
use Quiote\Exception\ConfigurationException;

/**
 * FactoryConfigHandler allows you to specify which factory implementation
 * the system will use.
 *
 * The factory ordering/startup-sequence/must_implement logic in
 * getFactoryDefinitions() is pure PHP with no XML-specific content at
 * all, so it lives in code rather than in the configuration. The
 * canonical array is exactly the per-factory `class`/`params` pairs
 * declared in the source (XML, PHP, or YAML):
 *   [
 *     'validation_manager' => ['class' => 'Some\Class', 'params' => [...]],
 *     'response' => ['class' => '...', 'params' => [...]],
 *     // one entry per <factory-name> child element the XML configuration
 *     // (or, for a PHP/YAML file, top-level key) actually declares.
 *   ]
 * Every factory getFactoryDefinitions() names is read, so the canonical
 * array is a faithful reading of the source and does not depend on any
 * runtime setting; whether a declared factory is then built is decided
 * in executeArray(). A PHP-array/YAML factories file is simply this same
 * map written directly, e.g. `return ['database_manager' => ['class' =>
 * ..., 'params' => [...]], ...];`.
 * @since      1.0.0
 * @version    1.0.0
 */
class FactoryConfigHandler extends XmlConfigHandler implements IArrayConfigHandler, ISchemaAwareConfigHandler, IPositionAwareConfigHandler
{
	const XML_NAMESPACE = 'http://quiote.dev/quiote/config/parts/factories/1.1';

	/**
	 * No required list: which factories must be present depends on runtime
	 * settings (translation_manager on core.use_translation), so that is a
	 * semantic check in executeArray() rather than a static array shape.
	 */
	public function schema(): Rule
	{
		$names = array_values(array_filter(array_keys($this->getFactoryDefinitions()), 'is_string'));
		$entry = Rule::struct([
			'class' => Rule::phpClass(nullable: true),
			'params' => Rule::mixed(),
		]);

		return Rule::struct(array_combine($names, array_fill(0, count($names), $entry)));
	}

	/**
	 * The fixed factory ordering/startup-sequence definition. Order
	 * matters (database manager before storage/user, etc.) and is
	 * unrelated to config format -- see class docs. A bare string value
	 * (rather than name => info) is a "call startup() now" marker for the
	 * factory named by that string, interleaved with the declarations.
	 *
	 * 'enabled' and 'required' are separate questions, and conflating them
	 * makes a factory either unconfigurable or impossible to switch off:
	 *   - 'enabled' asks whether the slot participates at all. It is the
	 *     only flag allowed to depend on a runtime setting, and when it is
	 *     false the slot is not built even if the application declares it.
	 *   - 'required' asks, of an enabled slot, whether a declaration must
	 *     be present: true means "throw if absent", false means "build it
	 *     when declared, do nothing when not".
	 * 'var' names the Context property an eagerly built factory lands in;
	 * null means the slot is instantiated on demand instead.
	 * @return array<int|string, string|array{enabled: bool, required: bool, var: string|null, must_implement: array<int, string>}>
	 */
	private function getFactoryDefinitions(): array
	{
		return [
			// Validation manager remains a required factory (middleware replaces filters)
			'validation_manager' => [
				'enabled' => true,
				'required' => true,
				'var' => null,
				'must_implement' => [],
			],
			// Response factory info (global response instance)
			'response' => [
				'enabled' => true,
				'required' => true,
				'var' => null,
				'must_implement' => [],
			],
			// Order: database manager must be instantiated (and startup run) BEFORE storage & user.
			'database_manager' => [
				'enabled' => true,
				'required' => true,
				'var' => 'databaseManager',
				'must_implement' => [],
			],
			'database_manager', // startup()
			// Translation is opt-in: with core.use_translation off the slot is
			// inert, so a <translation_manager> element left declared in the
			// configuration is read but never built. Once the setting is on the
			// declaration becomes mandatory -- see missingFactoryHint().
			'translation_manager' => [
				'enabled' => Config::getBool('core.use_translation', false),
				'required' => true,
				'var' => 'translationManager',
				'must_implement' => [],
			],
			'routing' => [
				'enabled' => true,
				'required' => true,
				'var' => 'routing',
				'must_implement' => [],
			],
			// Ensure a legacy request object exists for templates/views and worker-mode recreation
			'request' => [
				'enabled' => true,
				'required' => true,
				'var' => 'request',
				'must_implement' => [],
			],
			'controller' => [
				'enabled' => true,
				'required' => true,
				'var' => 'controller',
				'must_implement' => [],
			],
			// The session backend. Optional: a context with no session at all --
			// a console command, a queue worker, a stateless API -- configures
			// nothing here and gets a NullSessionBag.
			//
			// 'var' is deliberately null: the codegen's instantiating branch
			// emits new $class(); $obj->initialize($context, $params), and no
			// SessionPersistenceInterface implementation has that shape --
			// SessionFactoryInterface exists precisely to bridge that, and is
			// reached through the factory-info branch instead.
			'session' => [
				'enabled' => true,
				'required' => false,
				'var' => null,
				'must_implement' => [\Quiote\Session\SessionFactoryInterface::class],
			],
			'user' => [
				'enabled' => true,
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
	public function execute(XmlConfigDomDocument $document): mixed
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

				// Every known factory is read, whether or not it is enabled or
				// required: the canonical array reports what the source
				// declares, and executeArray() decides what to do with it.
				// Filtering here instead would make an optional slot such as
				// `session` unconfigurable, because "not enabled" and "declared
				// but not built" would both arrive as "not in the source".
				$element = $configuration->getChild($factory);
				if ($element !== null) {
					$data[$factory] ??= ['class' => null, 'params' => []];
					$data[$factory]['class'] = $element->getAttribute('class', $data[$factory]['class']);
					$data[$factory]['params'] = $element->getQuioteParameters($data[$factory]['params']);
				}
			}
		}

		return $data;
	}

	/**
	 * @return array{data: array<string, array{class: string|null, params: array<mixed>}>, positions: array<string, array{file: string, line: int}>}
	 */
	public function toCanonicalArrayWithPositions(XmlConfigDomDocument $document, ElementPositionIndex $positions): array
	{
		$document->setDefaultNamespace(self::XML_NAMESPACE, 'factories');

		$data = [];
		$elementPositions = [];
		$factories = $this->getFactoryDefinitions();

		foreach ($document->getConfigurationElements() as $configuration) {
			foreach ($factories as $factory => $info) {
				if (!is_string($factory) || !is_array($info)) {
					continue;
				}

				// Every known factory is read -- see toCanonicalArray().
				$element = $configuration->getChild($factory);
				if ($element !== null) {
					$data[$factory] ??= ['class' => null, 'params' => []];
					$data[$factory]['class'] = $element->getAttribute('class', $data[$factory]['class']);
					$data[$factory]['params'] = $element->getQuioteParameters($data[$factory]['params']);

					$position = $positions->forElement($element);
					if ($position !== null) {
						$elementPositions["{$factory}.class"] = $position;
					}
				}
			}
		}

		return ['data' => $data, 'positions' => $elementPositions];
	}

	/**
	 * Actionable, factory-specific guidance appended to the generic "missing
	 * or incomplete entry" error. Some factories (translation_manager) are
	 * only enabled once a core.use_* flag is on, and a freshly scaffolded
	 * app's factories file has no entry for them at all -- the generic
	 * message alone gives no hint that a new entry needs adding, or what
	 * class to point it at.
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
	public function executeArray(array $config, ?string $sourceRef = null): mixed
	{
		$factories = $this->getFactoryDefinitions();
		$data = $config;

		$operations = [];
		$factorySlots = [];
		$shutdownOrder = [];
		/** @var array<string, true> $built roles an OP_BUILD was emitted for */
		$built = [];

		foreach ($factories as $factory => $info) {
			if (is_array($info)) {
				// A disabled slot takes no part, however the application
				// declared it: the setting that switches it off has to win over
				// the configuration, or turning translation off would leave a
				// stale <translation_manager> element still building one.
				if (!$info['enabled']) {
					continue;
				}

				// An optional slot is skipped only when the application did not
				// configure it; skipping a configured one would silently drop it.
				if (!$info['required'] && (!isset($data[$factory]) || $data[$factory]['class'] === null)) {
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
					// Built eagerly by ComponentInstaller. The role, not $info['var'], is what
					// travels: the compiled file names configuration roles and never a property.
					$operations[] = [
						'op' => FactoryDefinitions::OP_BUILD,
						'role' => (string) $factory,
						'class' => $class,
						'parameters' => $data[$factory]['params'],
					];
					$built[(string) $factory] = true;
				} else {
					// Instantiated on demand rather than at boot.
					$factorySlots[(string) $factory] = [
						'class' => $class,
						'parameters' => $data[$factory]['params'],
					];
				}
			} else {
				// A bare string is a "start this role up now" marker, interleaved with the
				// declarations because the order matters -- the database manager has to be up
				// before the user that reads through it is built.
				// Only a role that was actually built eagerly can be started up,
				// which rules out the disabled, the undeclared-and-optional and
				// the built-on-demand in one check. Markers always follow their
				// own declaration, so $built is complete by the time one is read.
				if (!isset($built[$info])) {
					continue;
				}

				$operations[] = [
					'op' => FactoryDefinitions::OP_STARTUP,
					'role' => (string) $info,
				];
				// Shutdown is the reverse of startup.
				array_unshift($shutdownOrder, (string) $info);
			}
		}

		// Data, not statements. The compiled file returns a declaration; ComponentInstaller
		// carries it out. It has no access to whatever includes it, which is the whole point --
		// the previous form assigned into Context's private properties and broke whenever one
		// of them was renamed.
		$definitions = [
			'operations' => $operations,
			'factories' => $factorySlots,
			'shutdownOrder' => $shutdownOrder,
		];

		return $definitions;
	}
}

?>
