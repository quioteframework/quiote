<?php
namespace Quiote\Config;

use Quiote\Config\Format\Xml\ElementPositionIndex;
use Quiote\Config\Schema\Rule;
use Quiote\Config\Util\DOM\XmlConfigDomDocument;
use Quiote\Exception\ParseException;
use Quiote\Util\Toolkit;

/**
 * CachingConfigHandler compiles the per-action configuration files placed
 * in the "cache" subfolder of a module directory.
 *
 * Migrated to IArrayConfigHandler (phase 2). Canonical schema is exactly
 * the `$cachings` map execute() used
 * to build inline: request method (or '*') => ['lifetime' => ..., 'groups' => [...],
 * 'views' => ..., 'action_attributes' => [...], 'output_types' => [...]].
 * @since      1.0.0
 * @version    1.0.0
 */
class CachingConfigHandler extends XmlConfigHandler implements IArrayConfigHandler, ISchemaAwareConfigHandler, IPositionAwareConfigHandler
{
	const XML_NAMESPACE = 'http://quiote.dev/quiote/config/parts/caching/1.1';

	/**
	 * "layers" is a polymorphic per-layer-name map (true, or a list of slot
	 * names) not modeled key-by-key here -- structural parity stops at the
	 * known, fixed keys of a caching entry and an output-type entry; the
	 * dynamic method/output-type/layer-name keys themselves are, correctly,
	 * unconstrained (Dict), same as SettingConfigHandler's open shape.
	 */
	public function schema(): Rule
	{
		$group = Rule::struct([
			'name' => Rule::mixed(),
			'source' => Rule::string(nullable: true),
			'namespace' => Rule::string(nullable: true),
		], required: ['name', 'source', 'namespace']);

		$requestAttribute = Rule::struct([
			'name' => Rule::mixed(),
			'namespace' => Rule::string(nullable: true),
		], required: ['name', 'namespace']);

		$outputType = Rule::struct([
			'layers' => Rule::mixed(),
			'template_variables' => Rule::listOf(Rule::mixed()),
			'request_attributes' => Rule::listOf($requestAttribute),
			'request_attribute_namespaces' => Rule::listOf(Rule::mixed()),
		], required: ['layers', 'template_variables', 'request_attributes', 'request_attribute_namespaces']);

		$caching = Rule::struct([
			'lifetime' => Rule::string(nullable: true),
			'groups' => Rule::listOf($group),
			'views' => Rule::listOf(Rule::mixed(), nullable: true),
			'action_attributes' => Rule::listOf(Rule::mixed()),
			'output_types' => Rule::dictOf($outputType),
		], required: ['lifetime', 'groups', 'views', 'action_attributes', 'output_types']);

		return Rule::dictOf($caching);
	}

	/**
	 * @throws     \Quiote\Exception\UnreadableException If a requested configuration
	 *                                             file does not exist or is not
	 *                                             readable.
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
								$layerName = $layer->getAttribute('name');
								if ($layerName === null || $layerName === '') {
									throw new ParseException(sprintf(
										'Configuration file "%s" has a <layer> element missing its required "name" attribute',
										$document->documentURI
									));
								}

								$include = Toolkit::literalize($layer->getAttribute('include', 'true'));
								if (($layer->has('slots') && !$layer->hasAttribute('include')) || !$include) {
									$slots = [];
									if ($layer->has('slots')) {
										foreach ($layer->get('slots') as $slot) {
											$slots[] = $slot->getValue();
										}
									}
									$layers[$layerName] = $slots;
								} else {
									$layers[$layerName] = true;
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
					if (!Toolkit::literalize($caching->getAttribute('enabled', 'true'))) {
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

	/**
	 * Positions are only tracked for each caching entry's own "lifetime"
	 * key, at the <caching> element's line -- a reasonable top-level anchor
	 * without mirroring the full recursive output_types/layers/slots walk
	 * above, which polymorphic "layers" values (true|list<string>) don't
	 * cleanly reduce to a single leaf position anyway.
	 * @return array{data: array<string, array<string, mixed>>, positions: array<string, array{file: string, line: int}>}
	 */
	public function toCanonicalArrayWithPositions(XmlConfigDomDocument $document, ElementPositionIndex $positions): array
	{
		$document->setDefaultNamespace(self::XML_NAMESPACE, 'caching');

		$data = $this->toCanonicalArray($document);
		$elementPositions = [];

		foreach ($document->getConfigurationElements() as $cfg) {
			if (!$cfg->has('cachings')) {
				continue;
			}

			foreach ($cfg->get('cachings') as $caching) {
				$position = $positions->forElement($caching);
				if ($position === null) {
					continue;
				}

				$methods = array_map(trim(...), explode(' ', (string) $caching->getAttribute('method', '*')));
				foreach ($methods as $method) {
					if (isset($data[$method])) {
						$elementPositions["{$method}.lifetime"] = $position;
					}
				}
			}
		}

		return ['data' => $data, 'positions' => $elementPositions];
	}

	/**
	 * @param array<string, mixed> $config
	 */
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
