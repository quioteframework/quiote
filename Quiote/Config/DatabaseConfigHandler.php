<?php
namespace Quiote\Config;

use Quiote\Config\Util\DOM\XmlConfigDomDocument;
use Quiote\Exception\ConfigurationException;
use Quiote\Exception\ParseException;

/**
 * DatabaseConfigHandler allows you to setup database connections in a
 * configuration file that will be created for you automatically upon first
 * request.
 * @since      1.0.0
 * @version    1.0.0
 */
class DatabaseConfigHandler extends XmlConfigHandler
{
	const XML_NAMESPACE = 'http://quiote.dev/quiote/config/parts/databases/1.1';
	
	/**
	 * Execute this configuration handler.
	 * @param      XmlConfigDomDocument The document to parse.
	 * @return     string Data to be written to a cache file.
	 * @throws     <b>ParseException</b> If a requested configuration file is
	 *                                        improperly formatted.
	 * @since      1.0.0
	 */
	public function execute(XmlConfigDomDocument $document) : string
	{
		// set up our default namespace
		$document->setDefaultNamespace(self::XML_NAMESPACE, 'databases');
		
		$databases = [];
		$default = null;
		foreach($document->getConfigurationElements() as $configuration) {
			if(!$configuration->hasChildren('databases')) {
				continue;
			}
			
			$databasesElement = $configuration->getChild('databases');
			
			// make sure we have a default database exists
			if(!$databasesElement->hasAttribute('default') && $default === null) {
				// missing default database
				$error = 'Configuration file "%s" must specify a default database configuration';
				$error = sprintf($error, $document->documentURI);

				throw new ParseException($error);
			}
			if($databasesElement->hasAttribute('default')) {
				$default = $databasesElement->getAttribute('default');
			}

			// let's do our fancy work
			foreach($configuration->get('databases') as $database) {
				$name = $database->getAttribute('name');

				if(!isset($databases[$name])) {
					$databases[$name] = ['parameters' => []];

					if(!$database->hasAttribute('class')) {
						$error = 'Configuration file "%s" specifies database "%s" with missing class key';
						$error = sprintf($error, $document->documentURI, $name);

						throw new ParseException($error);
					}
				}

				$databases[$name]['class'] = $database->hasAttribute('class') ? $database->getAttribute('class') : $databases[$name]['class'];

				$databases[$name]['parameters'] = $database->getQuioteParameters($databases[$name]['parameters']);
			}
		}

		if(!$databases) {
			// we have no connections
			$error = 'Configuration file "%s" does not contain any database connections.';
			$error = sprintf($error, $document->documentURI);
			throw new ConfigurationException($error);
		}

		$data = [];

		foreach($databases as $name => $db) {
			// append new data
			$data[] = sprintf('$database = new %s();', $db['class']);
			$data[] = sprintf('$this->databases[%s] = $database;', var_export($name, true));
			$data[] = sprintf('$database->initialize($this, %s);', var_export($db['parameters'], true));
		}

		if(!isset($databases[$default])) {
			$error = 'Configuration file "%s" specifies undefined default database "%s".';
			$error = sprintf($error, $document->documentURI, $default);
			throw new ConfigurationException($error);
		}

		$data[] = sprintf('$this->defaultDatabaseName = %s;', var_export($default, true));

		return $this->generate($data, $document->documentURI);
	}
}

?>