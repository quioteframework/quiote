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
	 * @throws     \Quiote\Exception\ParseException If a requested configuration file is
	 *                                        improperly formatted.
	 * @since      1.0.0
	 */
	public function execute(XmlConfigDomDocument $document): string
	{
		return $this->executeArray($this->toCanonicalArray($document), $document->documentURI);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function toCanonicalArray(XmlConfigDomDocument $document): array
	{
		// set up our default namespace
		$document->setDefaultNamespace(self::XML_NAMESPACE, 'suite');

		$data = [];
		// loop over <configuration> elements
		foreach ($document->getConfigurationElements() as $configuration) {
			// get() only ever selects element nodes, and registerNodeClass()
			// guarantees those are always XmlConfigDomElement, never a vanilla DOMNode.
			/** @var iterable<int, \Quiote\Config\Util\DOM\XmlConfigDomElement> $suites */
			$suites = $configuration->get('suites');
			foreach ($suites as $current) {
				$includes = [];
				/** @var iterable<int, \Quiote\Config\Util\DOM\XmlConfigDomElement> $includeNodes */
				$includeNodes = $current->get('includes');
				foreach ($includeNodes as $include) {
					$includes[] = $include->textContent;
				}

				$excludes = [];
				/** @var iterable<int, \Quiote\Config\Util\DOM\XmlConfigDomElement> $excludeNodes */
				$excludeNodes = $current->get('excludes');
				foreach ($excludeNodes as $exclude) {
					$excludes[] = $exclude->textContent;
				}

				$suite = [
					'class' => $current->getAttribute('class', 'TestSuite'),
					'base' => $current->getAttribute('base', 'tests/'),
					'includes' => $includes,
					'excludes' => $excludes
				];

				$suite['testfiles'] = [];
				/** @var iterable<int, \Quiote\Config\Util\DOM\XmlConfigDomElement> $testfileNodes */
				$testfileNodes = $current->get('testfiles');
				foreach ($testfileNodes as $file) {
					$suite['testfiles'][] = $file->textContent;
				}

				$data[$current->getAttribute('name')] = $suite;
			}
		}

		return $data;
	}

	/**
	 * @param array<string, array<string, mixed>> $config
	 */
	public function executeArray(array $config, ?string $sourceRef = null): string
	{
		$code = 'return ' . var_export($config, true);
		return $this->generate($code, $sourceRef);
	}
}

?>
