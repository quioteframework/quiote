<?php
namespace Quiote\Config;

use Quiote\Config\Util\DOM\XmlConfigDomDocument;

/**
 * TestSuitesConfigHandler reads the testsuites configuration files to determine
 * the available suites and their tests.
 *
 * Migrated to IArrayConfigHandler (phase 2). Canonical schema, already
 * exactly what execute() built inline:
 *   ['suite_name' => ['class' => ..., 'base' => ..., 'includes' => [...],
 *                      'excludes' => [...], 'testfiles' => [...]]]
 * @since      1.0.0
 * @version    1.0.0
 */
class TestSuitesConfigHandler extends XmlConfigHandler implements IArrayConfigHandler
{
	const XML_NAMESPACE = 'http://quiote.dev/quiote/config/parts/testing/suites/1.1';

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
		$document->setDefaultNamespace(self::XML_NAMESPACE, 'suite');

		$data = [];
		// loop over <configuration> elements
		foreach ($document->getConfigurationElements() as $configuration) {
			foreach ($configuration->get('suites') as $current) {
				$includes = [];
				foreach ($current->get('includes') as $include) {
					$includes[] = $include->textContent;
				}

				$excludes = [];
				foreach ($current->get('excludes') as $exclude) {
					$excludes[] = $exclude->textContent;
				}

				$suite = [
					'class' => $current->getAttribute('class', 'TestSuite'),
					'base' => $current->getAttribute('base', 'tests/'),
					'includes' => $includes,
					'excludes' => $excludes
				];

				$suite['testfiles'] = [];
				foreach ($current->get('testfiles') as $file) {
					$suite['testfiles'][] = $file->textContent;
				}

				$data[$current->getAttribute('name')] = $suite;
			}
		}

		return $data;
	}

	public function executeArray(array $config, ?string $sourceRef = null): string
	{
		$code = 'return ' . var_export($config, true);
		return $this->generate($code, $sourceRef);
	}
}

?>
