<?php
namespace Quiote\Config;

use Quiote\Config\Util\DOM\XmlConfigDomDocument;
use Quiote\Exception\ConfigurationException;
use Quiote\Util\Toolkit;

/**
 * OutputTypeConfigHandler handles output type configuration files.
 *
 * Migrated to IArrayConfigHandler (phase 2). Canonical schema:
 *   ['default' => 'output_type_name',
 *    'output_types' => ['name' => ['parameters' => [...], 'default_renderer' => ...,
 *        'renderers' => [...], 'layouts' => [...], 'default_layout' => ...,
 *        'exception_template' => ...|null]]]
 * All keys in the output-type, renderer, layout, layer, and slot sub-arrays are
 * optional when using PHP/YAML format — executeArray() applies the same defaults
 * that XML provides via getAttribute($name, $default), so terse configs work.
 * The duplicate-name and missing-default checks are inherently tied to
 * the order <ae:configuration> blocks are walked in (the last block's
 * `default` attribute wins), so they stay in toCanonicalArray() exactly
 * as before; only the final "undefined default output type" check -- a
 * pure function of the finished canonical array -- moved to executeArray().
 * @since      1.0.0
 * @version    1.0.0
 */
class OutputTypeConfigHandler extends XmlConfigHandler implements IArrayConfigHandler
{
	const XML_NAMESPACE = 'http://quiote.dev/quiote/config/parts/output_types/1.1';

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
	 * @return array{default: string|null, output_types: array<string, array<string, mixed>>}
	 */
	public function toCanonicalArray(XmlConfigDomDocument $document): array
	{
		// set up our default namespace
		$document->setDefaultNamespace(self::XML_NAMESPACE, 'output_types');

		// remember the config file path
		$config = $document->documentURI;

		$defaultLayerClassParam = $this->getParameter('default_layer_class', \Quiote\View\FileTemplateLayer::class);
		$defaultLayerClassStr = is_string($defaultLayerClassParam) ? $defaultLayerClassParam : \Quiote\View\FileTemplateLayer::class;

		$data = [];
		$defaultOt = null;
		foreach ($document->getConfigurationElements() as $cfg) {
			if (!$cfg->has('output_types')) {
				continue;
			}

			$otnames = [];
			foreach ($cfg->get('output_types') as $outputType) {
				$otname = (string) $outputType->getAttribute('name');
				if (in_array($otname, $otnames)) {
					throw new ConfigurationException('Duplicate Output Type "' . $otname . '" in ' . $config);
				}
				$otnames[] = $otname;
			}

			$outputTypesEl = $cfg->getChild('output_types');
			if ($outputTypesEl === null || !$outputTypesEl->hasAttribute('default')) {
				throw new ConfigurationException('No default Output Type specified in ' . $config);
			}

			foreach ($cfg->get('output_types') as $outputType) {
				$outputTypeName = (string) $outputType->getAttribute('name');
				$data[$outputTypeName] ??= ['parameters' => [], 'default_renderer' => null, 'renderers' => [], 'layouts' => [], 'default_layout' => null, 'exception_template' => null];
				if ($outputType->has('renderers')) {
					foreach ($outputType->get('renderers') as $renderer) {
						$rendererName = (string) $renderer->getAttribute('name');
						$data[$outputTypeName]['renderers'][$rendererName] = [
							'class' => $renderer->getAttribute('class'),
							'instance' => null,
							'parameters' => $renderer->getQuioteParameters([]),
						];
					}
					$renderersEl = $outputType->getChild('renderers');
					$data[$outputTypeName]['default_renderer'] = $renderersEl !== null ? $renderersEl->getAttribute('default') : null;
				}
				if ($outputType->has('layouts')) {
					foreach ($outputType->get('layouts') as $layout) {
						$layers = [];

						if ($layout->has('layers')) {
							foreach ($layout->get('layers') as $layer) {
								$slots = [];

								if ($layer->has('slots')) {
									foreach ($layer->get('slots') as $slot) {
										$slots[(string) $slot->getAttribute('name')] = [
											'action' => $slot->getAttribute('action'),
											'module' => $slot->getAttribute('module'),
											'output_type' => $slot->getAttribute('output_type'),
											'request_method' => $slot->getAttribute('method'),
											'parameters' => $slot->getQuioteParameters([]),
										];
									}
								}

								$layers[(string) $layer->getAttribute('name')] = [
									'class' => $layer->getAttribute('class', $defaultLayerClassStr),
									'parameters' => $layer->getQuioteParameters([]),
									'renderer' => $layer->getAttribute('renderer'),
									'slots' => $slots,
								];
							}
						}

						$data[$outputTypeName]['layouts'][(string) $layout->getAttribute('name')] = [
							'layers' => $layers,
							'parameters' => $layout->getQuioteParameters([]),
						];
					}
					$layoutsEl = $outputType->getChild('layouts');
					$data[$outputTypeName]['default_layout'] = $layoutsEl !== null ? $layoutsEl->getAttribute('default') : null;
				}
				if ($outputType->hasAttribute('exception_template')) {
					$exceptionTemplate = Toolkit::expandDirectives((string) $outputType->getAttribute('exception_template'));
					if ($exceptionTemplate === null || !is_readable($exceptionTemplate)) {
						throw new ConfigurationException('Exception template "' . $exceptionTemplate . '" does not exist or is unreadable');
					}
					$data[$outputTypeName]['exception_template'] = $exceptionTemplate;
				}
				$data[$outputTypeName]['parameters'] = $outputType->getQuioteParameters($data[$outputTypeName]['parameters']);
			}
			$defaultOt = $outputTypesEl->getAttribute('default');
		}

		return ['default' => $defaultOt, 'output_types' => $data];
	}

	/**
	 * @param array{default?: string|null, output_types?: array<string, array<string, mixed>>} $config
	 */
	public function executeArray(array $config, ?string $sourceRef = null): string
	{
		$defaultOt = $config['default'] ?? null;
		$data = $config['output_types'] ?? [];

		if ($defaultOt === null || !isset($data[$defaultOt])) {
			$error = 'Configuration file "%s" specifies undefined default Output Type "%s".';
			$error = sprintf($error, $sourceRef, $defaultOt);
			throw new ConfigurationException($error);
		}

		$defaultLayerClass = $this->getParameter('default_layer_class', \Quiote\View\FileTemplateLayer::class);

		$code = [];
		foreach ($data as $outputTypeName => $outputType) {
			$outputType += [
				'parameters' => [],
				'default_renderer' => null,
				'renderers' => [],
				'layouts' => [],
				'default_layout' => null,
				'exception_template' => null,
			];

			$renderers = [];
			/** @var array<string, mixed> $rawRenderers */
			$rawRenderers = is_array($outputType['renderers']) ? $outputType['renderers'] : [];
			foreach ($rawRenderers as $rendererName => $renderer) {
				$renderers[$rendererName] = (is_array($renderer) ? $renderer : []) + [
					'instance' => null,
					'parameters' => [],
				];
			}

			$layouts = [];
			/** @var array<string, mixed> $rawLayouts */
			$rawLayouts = is_array($outputType['layouts']) ? $outputType['layouts'] : [];
			foreach ($rawLayouts as $layoutName => $layout) {
				$layout = (is_array($layout) ? $layout : []) + ['layers' => [], 'parameters' => []];
				$layers = [];
				/** @var array<string, mixed> $rawLayers */
				$rawLayers = is_array($layout['layers']) ? $layout['layers'] : [];
				foreach ($rawLayers as $layerName => $layer) {
					$layer = (is_array($layer) ? $layer : []) + [
						'class' => $defaultLayerClass,
						'parameters' => [],
						'renderer' => null,
						'slots' => [],
					];
					$slots = [];
					/** @var array<string, mixed> $rawSlots */
					$rawSlots = is_array($layer['slots']) ? $layer['slots'] : [];
					foreach ($rawSlots as $slotName => $slot) {
						$slots[$slotName] = (is_array($slot) ? $slot : []) + [
							'action' => '',
							'module' => '',
							'output_type' => '',
							'request_method' => '',
							'parameters' => [],
						];
					}
					$layer['slots'] = $slots;
					$layers[$layerName] = $layer;
				}
				$layout['layers'] = $layers;
				$layouts[$layoutName] = $layout;
			}

			$code[] = '$ot = new Quiote\Controller\OutputType();';
			$code[] = sprintf(
				'$ot->initialize($this->context, %s, %s, %s, %s, %s, %s, %s);',
				var_export($outputType['parameters'], true),
				var_export($outputTypeName, true),
				var_export($renderers, true),
				var_export($outputType['default_renderer'], true),
				var_export($layouts, true),
				var_export($outputType['default_layout'], true),
				var_export($outputType['exception_template'], true)
			);
			$code[] = sprintf('$this->outputTypes[%s] = $ot;', var_export($outputTypeName, true));
		}
		$code[] = sprintf('$this->defaultOutputType = %s;', var_export($defaultOt, true));

		return $this->generate($code, $sourceRef);
	}
}

?>
