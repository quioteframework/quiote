<?php
namespace Quiote\Config;

use Quiote\Config\Util\DOM\XmlConfigDomDocument;

/**
 * TestSuitesConfigHandler reads the testsuites configuration files to determine 
 * the available suites and their tests.
 * @since      1.0.0
 * @version    1.0.0
 */
class TestSuitesConfigHandler extends XmlConfigHandler
{
	const XML_NAMESPACE = 'http://quiote.dev/quiote/config/parts/testing/suites/1.1';
	
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
		$document->setDefaultNamespace(self::XML_NAMESPACE, 'suite');
		
		// remember the config file path
		$config = $document->documentURI;
		
		$data = [];
		// loop over <configuration> elements
		foreach($document->getConfigurationElements() as $configuration) {
			foreach($configuration->get('suites') as $current) {
				$includes = [];
				foreach($current->get('includes') as $include) {
					$includes[] = $include->textContent;
				}
				
				$excludes = [];
				foreach($current->get('excludes') as $exclude) {
					$excludes[] = $exclude->textContent;
				}
				
				$suite =  [
					'class' => $current->getAttribute('class', 'TestSuite'),
					'base' => $current->getAttribute('base', 'tests/'),
					'includes' => $includes,
					'excludes' => $excludes
				];
				
				$suite['testfiles'] = [];
				foreach($current->get('testfiles') as $file) {
					$suite['testfiles'][] = $file->textContent;
				}
				
				$data[$current->getAttribute('name')] = $suite;
			}
		}
		$code = 'return '.var_export($data, true);
		return $this->generate($code, $config);
	}
}

?>