<?php
namespace Quiote\Config;

use Quiote\Config\Util\DOM\XmlConfigDomDocument;
use Quiote\Exception\ConfigurationException;
use Quiote\Exception\FactoryException;
use Quiote\Util\Toolkit;

/**
 * FilterConfigHandler allows you to register filters with the system.
 *
 * Migrated to IArrayConfigHandler (docs/CONFIG_SYSTEM_REWRITE_PLAN.md
 * phase 2). Canonical schema:
 *   ['filter_name' => ['class' => 'Some\Class', 'enabled' => bool, 'params' => [...]]]
 * The required-interface check derives its expected interface name from
 * the source file's own basename convention (`{type}_filters.xml` ->
 * `I{Type}Filter`) -- this is unrelated to XML and applies identically to
 * a `action_filters.php`/`.yaml` file with the same naming convention.
 * @since      1.0.0
 * @version    1.0.0
 */
class FilterConfigHandler extends XmlConfigHandler implements IArrayConfigHandler
{
	const XML_NAMESPACE = 'http://quiote.dev/quiote/config/parts/filters/1.1';

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
		$document->setDefaultNamespace(self::XML_NAMESPACE, 'filters');

		$filters = [];

		foreach ($document->getConfigurationElements() as $cfg) {
			if ($cfg->has('filters')) {
				foreach ($cfg->get('filters') as $filter) {
					$name = $filter->getAttribute('name', Toolkit::uniqid());

					if (!isset($filters[$name])) {
						$filters[$name] = ['params' => [], 'enabled' => Toolkit::literalize($filter->getAttribute('enabled', true))];
					} else {
						$filters[$name]['enabled'] = Toolkit::literalize($filter->getAttribute('enabled', $filters[$name]['enabled']));
					}

					if ($filter->hasAttribute('class')) {
						$filters[$name]['class'] = $filter->getAttribute('class');
					}

					$filters[$name]['params'] = $filter->getQuioteParameters($filters[$name]['params']);
				}
			}
		}

		return $filters;
	}

	public function executeArray(array $config, ?string $sourceRef = null): string
	{
		$data = [];

		foreach ($config as $name => $filter) {
			if (stripos((string) $name, 'quiote') === 0) {
				throw new ConfigurationException('Filter names must not start with "quiote".');
			}
			if (!isset($filter['class'])) {
				throw new ConfigurationException('No class name specified for filter "' . $name . '" in ' . $sourceRef);
			}
			if ($filter['enabled']) {
				$rc = new \ReflectionClass($filter['class']);
				$if = 'Quiote\Filter\I' . ucfirst(strtolower(substr(basename((string) $sourceRef), 0, strpos(basename((string) $sourceRef), '_filters')))) . 'Filter';
				if (!$rc->implementsInterface($if)) {
					throw new FactoryException('Filter "' . $name . '" does not implement interface "' . $if . '"');
				}
				$data[] = '$filter = new ' . $filter['class'] . '();';
				$data[] = '$filter->initialize($this->context, ' . var_export($filter['params'], true) . ');';
				$data[] = '$filters[' . var_export($name, true) . '] = $filter;';
			}
		}

		return $this->generate($data, $sourceRef);
	}
}

?>
