<?php
namespace Quiote\Config;

use Quiote\Config\Util\DOM\XmlConfigDomDocument;
use Quiote\Exception\ConfigurationException;
use Quiote\Util\Toolkit;

/**
 * OutputTypeConfigHandler handles output type configuration files.
 * @since      1.0.0
 * @version    1.0.0
 */
class OutputTypeConfigHandler extends XmlConfigHandler
{
	const XML_NAMESPACE = 'http://quiote.dev/quiote/config/parts/output_types/1.1';
	
	/**
	 * Execute this configuration handler.
	 * @param      XmlConfigDomDocument The document to parse.
	 * @return     string Data to be written to a cache file.
	 * @throws     <b>UnreadableException</b> If a requested configuration
	 *                                             file does not exist or is not
	 *                                             readable.
	 * @throws     <b>ParseException</b> If a requested configuration file is
	 *                                        improperly formatted.
	 * @since      1.0.0
	 */
	public function execute(XmlConfigDomDocument $document) : string
	{
		// set up our default namespace
		$document->setDefaultNamespace(self::XML_NAMESPACE, 'output_types');
		
		// remember the config file path
		$config = $document->documentURI;
		
		$data = [];
		$defaultOt = null;
		foreach($document->getConfigurationElements() as $cfg) {
			if(!$cfg->has('output_types')) {
				continue;
			}
			
			$otnames = [];
			foreach($cfg->get('output_types') as $outputType) {
				$otname = $outputType->getAttribute('name');
				if(in_array($otname, $otnames)) {
					throw new ConfigurationException('Duplicate Output Type "' . $otname . '" in ' . $config);
				}
				$otnames[] = $otname;
			}

			if(!$cfg->getChild('output_types')->hasAttribute('default')) {
				throw new ConfigurationException('No default Output Type specified in ' . $config);
			}

			foreach($cfg->get('output_types') as $outputType) {
				$outputTypeName = $outputType->getAttribute('name');
				$data[$outputTypeName] ??= ['parameters' => [], 'default_renderer' => null, 'renderers' => [], 'layouts' => [], 'default_layout' => null, 'exception_template' => null];
				if($outputType->has('renderers')) {
					foreach($outputType->get('renderers') as $renderer) {
						$rendererName = $renderer->getAttribute('name');
						$data[$outputTypeName]['renderers'][$rendererName] = [
							'class' => $renderer->getAttribute('class'),
							'instance' => null,
							'parameters' => $renderer->getQuioteParameters([]),
						];
					}
					$data[$outputTypeName]['default_renderer'] = $outputType->getChild('renderers')->getAttribute('default');
				}
				if($outputType->has('layouts')) {
					foreach($outputType->get('layouts') as $layout) {
						$layers = [];
						
						if($layout->has('layers')) {
							foreach($layout->get('layers') as $layer) {
								$slots = [];
								
								if($layer->has('slots')) {
									foreach($layer->get('slots') as $slot) {
										$slots[$slot->getAttribute('name')] = [
											'action' => $slot->getAttribute('action'),
											'module' => $slot->getAttribute('module'),
											'output_type' => $slot->getAttribute('output_type'),
											'request_method' => $slot->getAttribute('method'),
											'parameters' => $slot->getQuioteParameters([]),
										];
									}
								}
								
								$layers[$layer->getAttribute('name')] = [
									'class' => $layer->getAttribute('class', $this->getParameter('default_layer_class', \Quiote\View\FileTemplateLayer::class)),
									'parameters' => $layer->getQuioteParameters([]),
									'renderer' => $layer->getAttribute('renderer'),
									'slots' => $slots,
								];
							}
						}
						
						$data[$outputTypeName]['layouts'][$layout->getAttribute('name')] = [
							'layers' => $layers,
							'parameters' => $layout->getQuioteParameters([]),
						];
					}
					$data[$outputTypeName]['default_layout'] = $outputType->getChild('layouts')->getAttribute('default');
				}
				if($outputType->hasAttribute('exception_template')) {
					$data[$outputTypeName]['exception_template'] = Toolkit::expandDirectives($outputType->getAttribute('exception_template'));
					if(!is_readable($data[$outputTypeName]['exception_template'])) {
						throw new ConfigurationException('Exception template "' . $data[$outputTypeName]['exception_template'] . '" does not exist or is unreadable');
					}
				}
				$data[$outputTypeName]['parameters'] = $outputType->getQuioteParameters($data[$outputTypeName]['parameters']);
			}
			$defaultOt = $cfg->getChild('output_types')->getAttribute('default');
		}

		if(!isset($data[$defaultOt])) {
			$error = 'Configuration file "%s" specifies undefined default Output Type "%s".';
			$error = sprintf($error, $document->documentURI, $defaultOt);
			throw new ConfigurationException($error);
		}
		
		$code = [];
		foreach($data as $outputTypeName => $outputType) {
			$code[] = '$ot = new Quiote\Controller\OutputType();';
			$code[] = sprintf(
				'$ot->initialize($this->context, %s, %s, %s, %s, %s, %s, %s);',
				var_export($outputType['parameters'], true),
				var_export($outputTypeName, true),
				var_export($outputType['renderers'], true),
				var_export($outputType['default_renderer'], true),
				var_export($outputType['layouts'], true),
				var_export($outputType['default_layout'], true),
				var_export($outputType['exception_template'], true)
			);
			$code[] = sprintf('$this->outputTypes[%s] = $ot;', var_export($outputTypeName, true));
		}
		$code[] = sprintf('$this->defaultOutputType = %s;', var_export($defaultOt, true));
		
		return $this->generate($code, $config);
	}
}

?>