<?php
namespace Quiote\Config;

use Quiote\Config\Util\DOM\XmlConfigDomDocument;
use Quiote\Exception\ConfigurationException;
use Quiote\Exception\ParseException;

/**
 * DatabaseConfigHandler allows you to setup database connections in a
 * configuration file that will be created for you automatically upon first
 * request.
 *
 * Migrated to IArrayConfigHandler (docs/CONFIG_SYSTEM_REWRITE_PLAN.md
 * phase 2). Canonical schema:
 *   ['default' => 'connection_name'|null,
 *    'databases' => ['connection_name' => ['class' => 'Some\Class', 'parameters' => [...]]]]
 * The "a default must be declared by the time a <databases> block is
 * seen" check is inherently about the order configuration blocks are
 * processed in, not just the final data shape, so it still runs during
 * toCanonicalArray()'s walk (throwing ParseException exactly as before);
 * the "no databases at all" / "undefined default" checks only depend on
 * the final canonical array and have moved to executeArray().
 * @since      1.0.0
 * @version    1.0.0
 */
class DatabaseConfigHandler extends XmlConfigHandler implements IArrayConfigHandler
{
	const XML_NAMESPACE = 'http://quiote.dev/quiote/config/parts/databases/1.1';

	/**
	 * @throws     <b>ParseException</b> If a requested configuration file is
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
		$document->setDefaultNamespace(self::XML_NAMESPACE, 'databases');

		$databases = [];
		$default = null;
		foreach ($document->getConfigurationElements() as $configuration) {
			if (!$configuration->hasChildren('databases')) {
				continue;
			}

			$databasesElement = $configuration->getChild('databases');

			// make sure we have a default database exists
			if (!$databasesElement->hasAttribute('default') && $default === null) {
				// missing default database
				$error = 'Configuration file "%s" must specify a default database configuration';
				$error = sprintf($error, $document->documentURI);

				throw new ParseException($error);
			}
			if ($databasesElement->hasAttribute('default')) {
				$default = $databasesElement->getAttribute('default');
			}

			// let's do our fancy work
			foreach ($configuration->get('databases') as $database) {
				$name = $database->getAttribute('name');

				if (!isset($databases[$name])) {
					$databases[$name] = ['parameters' => []];

					if (!$database->hasAttribute('class')) {
						$error = 'Configuration file "%s" specifies database "%s" with missing class key';
						$error = sprintf($error, $document->documentURI, $name);

						throw new ParseException($error);
					}
				}

				$databases[$name]['class'] = $database->hasAttribute('class') ? $database->getAttribute('class') : $databases[$name]['class'];

				$databases[$name]['parameters'] = $database->getQuioteParameters($databases[$name]['parameters']);
			}
		}

		return ['default' => $default, 'databases' => $databases];
	}

	public function executeArray(array $config, ?string $sourceRef = null): string
	{
		$default = $config['default'] ?? null;
		$databases = $config['databases'] ?? [];

		if (!$databases) {
			// we have no connections
			$error = 'Configuration file "%s" does not contain any database connections.';
			$error = sprintf($error, $sourceRef);
			throw new ConfigurationException($error);
		}

		$data = [];

		foreach ($databases as $name => $db) {
			// Resolve a short driver alias (e.g. "eloquent") to its adapter class
			// at compile time; a fully-qualified class name passes through
			// unchanged. Aliases are contributed by plugins during bootstrap,
			// which runs before any config is compiled. Resolution happens here
			// (not at runtime) to keep the generated code a plain `new <FQCN>()`.
			// NOTE: if the set of registered aliases can change between compiles,
			// fold \Quiote\Database\DatabaseDriverRegistry::aliases() into the
			// config-cache key (see docs/DATABASE_ADAPTERS_PLAN.md §7.1).
			$class = \Quiote\Database\DatabaseDriverRegistry::resolve($db['class']);

			// append new data
			$data[] = sprintf('$database = new %s();', $class);
			$data[] = sprintf('$this->databases[%s] = $database;', var_export($name, true));
			$data[] = sprintf('$database->initialize($this, %s);', var_export($db['parameters'], true));
		}

		if (!isset($databases[$default])) {
			$error = 'Configuration file "%s" specifies undefined default database "%s".';
			$error = sprintf($error, $sourceRef, $default);
			throw new ConfigurationException($error);
		}

		$data[] = sprintf('$this->defaultDatabaseName = %s;', var_export($default, true));

		return $this->generate($data, $sourceRef);
	}
}

?>
