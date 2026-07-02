<?php
namespace Quiote\Config;

use Quiote\Config\Util\DOM\XmlConfigDomDocument;
use Quiote\Util\Toolkit;

/**
 * CachingConfigHandler compiles the per-action configuration files placed
 * in the "cache" subfolder of a module directory.
 *
 * Migrated to IArrayConfigHandler (docs/CONFIG_SYSTEM_REWRITE_PLAN.md
 * phase 2). Canonical schema is exactly the `$cachings` map execute() used
 * to build inline: request method (or '*') => ['lifetime' => ..., 'groups' => [...],
 * 'views' => ..., 'action_attributes' => [...], 'output_types' => [...]].
 * @since      1.0.0
 * @version    1.0.0
 */
class CachingConfigHandler extends XmlConfigHandler implements IArrayConfigHandler
{
	const XML_NAMESPACE = 'http://quiote.dev/quiote/config/parts/caching/1.1';

	/**
	 * @throws     <b>UnreadableException</b> If a requested configuration
	 *                                             file does not exist or is not
	 *                                             readable.
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
		$document->setDefaultNamespace(self::XML_NAMESPACE, 'caching');

		$cachings = [];

		foreach ($document->getConfigurationElements() as $cfg) {
			if (!$cfg->has('cachings')) {
				continue;
			}

			foreach ($cfg->get('cachings') as $caching) {
				$groups = [];
				if ($caching->has('groups')) {
					foreach ($caching->get('groups') as $group) {
						$groups[] = ['name' => $group->getValue(), 'source' => $group->getAttribute('source', 'string'), 'namespace' => $group->getAttribute('namespace')];
					}
				}

				$actionAttributes = [];
				if ($caching->has('action_attributes')) {
					foreach ($caching->get('action_attributes') as $actionAttribute) {
						$actionAttributes[] = $actionAttribute->getValue();
					}
				}

				$views = null;
				if ($caching->has('views')) {
					$views = [];
					foreach ($caching->get('views') as $view) {
						if ($view->hasAttribute('module')) {
							$views[] = ['module' => $view->getAttribute('module'), 'view' => $view->getValue()];
						} else {
							$views[] = Toolkit::literalize($view->getValue());
						}
					}
				}

				$outputTypes = [];
				if ($caching->has('output_types')) {
					foreach ($caching->get('output_types') as $outputType) {
						$layers = null;
						if ($outputType->has('layers')) {
							$layers = [];
							foreach ($outputType->get('layers') as $layer) {
								$include = Toolkit::literalize($layer->getAttribute('include', 'true'));
								if (($layer->has('slots') && !$layer->hasAttribute('include')) || !$include) {
									$slots = [];
									if ($layer->has('slots')) {
										foreach ($layer->get('slots') as $slot) {
											$slots[] = $slot->getValue();
										}
									}
									$layers[$layer->getAttribute('name')] = $slots;
								} else {
									$layers[$layer->getAttribute('name')] = true;
								}
							}
						}

						$templateVariables = [];
						if ($outputType->has('template_variables')) {
							foreach ($outputType->get('template_variables') as $templateVariable) {
								$templateVariables[] = $templateVariable->getValue();
							}
						}

						$requestAttributes = [];
						if ($outputType->has('request_attributes')) {
							foreach ($outputType->get('request_attributes') as $requestAttribute) {
								$requestAttributes[] = ['name' => $requestAttribute->getValue(), 'namespace' => $requestAttribute->getAttribute('namespace')];
							}
						}

						$requestAttributeNamespaces = [];
						if ($outputType->has('request_attribute_namespaces')) {
							foreach ($outputType->get('request_attribute_namespaces') as $requestAttributeNamespace) {
								$requestAttributeNamespaces[] = $requestAttributeNamespace->getValue();
							}
						}

						$otnames = array_map(trim(...), explode(' ', (string) $outputType->getAttribute('name', '*')));
						foreach ($otnames as $otname) {
							$outputTypes[$otname] = [
								'layers' => $layers,
								'template_variables' => $templateVariables,
								'request_attributes' => $requestAttributes,
								'request_attribute_namespaces' => $requestAttributeNamespaces,
							];
						}
					}
				}

				$methods = array_map(trim(...), explode(' ', (string) $caching->getAttribute('method', '*')));
				foreach ($methods as $method) {
					if (!Toolkit::literalize($caching->getAttribute('enabled', true))) {
						unset($cachings[$method]);
					} else {
						$values = [
							'lifetime' => $caching->getAttribute('lifetime'),
							'groups' => $groups,
							'views' => $views,
							'action_attributes' => $actionAttributes,
							'output_types' => $outputTypes,
						];
						$cachings[$method] = $values;
					}
				}
			}
		}

		return $cachings;
	}

	public function executeArray(array $config, ?string $sourceRef = null): string
	{
		$code = [
			'$configs = ' . var_export($config, true) . ';',
			'if(isset($configs[$index = $container->getRequestMethod()]) || isset($configs[$index = "*"])) {',
			'	$isCacheable = true;',
			'	$config = $configs[$index];',
			'	if(is_array($config["views"])) {',
			'		foreach($config["views"] as &$view) {',
			'			if(!is_array($view)) {',
			'				if($view === null) {',
			'					$view = array(',
			'						"module" => null,',
			'						"name" => null',
			'					);',
			'				} else {',
			'					$view = array(',
			'						"module" => $moduleName,',
			'						"name" => Quiote\\Util\\Toolkit::evaluateModuleDirective(',
			'							$moduleName,',
			'							"quiote.view.name",',
			'							array(',
			'								"actionName" => $actionName,',
			'								"viewName" => $view,',
			'							)',
			'						)',
			'					);',
			'				}',
			'			}',
			'		}',
			'	}',
			'}',
		];

		return $this->generate($code, $sourceRef);
	}
}

?>
