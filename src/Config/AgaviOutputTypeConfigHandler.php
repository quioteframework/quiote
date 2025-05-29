<?php

// +---------------------------------------------------------------------------+
// | This file is part of the Agavi package.                                   |
// | Copyright (c) 2005-2011 the Agavi Project.                                |
// |                                                                           |
// | For the full copyright and license information, please view the LICENSE   |
// | file that was distributed with this source code. You can also view the    |
// | LICENSE file online at http://www.agavi.org/LICENSE.txt                   |
// |   vi: set noexpandtab:                                                    |
// |   Local Variables:                                                        |
// |   indent-tabs-mode: t                                                     |
// |   End:                                                                    |
// +---------------------------------------------------------------------------+
namespace Agavi\Config;

use Agavi\Config\Util\DOM\AgaviXmlConfigDomDocument;
use Agavi\Exception\AgaviConfigurationException;
use Agavi\Util\AgaviToolkit;

/**
 * AgaviOutputTypeConfigHandler handles output type configuration files.
 *
 * @package    agavi
 * @subpackage config
 *
 * @author     David Zülke <dz@bitxtender.com>
 * @copyright  Authors
 * @copyright  The Agavi Project
 *
 * @since      0.11.0
 *
 * @version    $Id$
 */
class AgaviOutputTypeConfigHandler extends AgaviXmlConfigHandler
{
	const XML_NAMESPACE = 'http://agavi.org/agavi/config/parts/output_types/1.1';
	
	/**
	 * Execute this configuration handler.
	 *
	 * @param      AgaviXmlConfigDomDocument The document to parse.
	 *
	 * @return     string Data to be written to a cache file.
	 *
	 * @throws     <b>AgaviUnreadableException</b> If a requested configuration
	 *                                             file does not exist or is not
	 *                                             readable.
	 * @throws     <b>AgaviParseException</b> If a requested configuration file is
	 *                                        improperly formatted.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function execute(AgaviXmlConfigDomDocument $document) : string
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
					throw new AgaviConfigurationException('Duplicate Output Type "' . $otname . '" in ' . $config);
				}
				$otnames[] = $otname;
			}

			if(!$cfg->getChild('output_types')->hasAttribute('default')) {
				throw new AgaviConfigurationException('No default Output Type specified in ' . $config);
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
							'parameters' => $renderer->getAgaviParameters([]),
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
											'parameters' => $slot->getAgaviParameters([]),
										];
									}
								}
								
								$layers[$layer->getAttribute('name')] = [
									'class' => $layer->getAttribute('class', $this->getParameter('default_layer_class', 'AgaviFileTemplateLayer')),
									'parameters' => $layer->getAgaviParameters([]),
									'renderer' => $layer->getAttribute('renderer'),
									'slots' => $slots,
								];
							}
						}
						
						$data[$outputTypeName]['layouts'][$layout->getAttribute('name')] = [
							'layers' => $layers,
							'parameters' => $layout->getAgaviParameters([]),
						];
					}
					$data[$outputTypeName]['default_layout'] = $outputType->getChild('layouts')->getAttribute('default');
				}
				if($outputType->hasAttribute('exception_template')) {
					$data[$outputTypeName]['exception_template'] = AgaviToolkit::expandDirectives($outputType->getAttribute('exception_template'));
					if(!is_readable($data[$outputTypeName]['exception_template'])) {
						throw new AgaviConfigurationException('Exception template "' . $data[$outputTypeName]['exception_template'] . '" does not exist or is unreadable');
					}
				}
				$data[$outputTypeName]['parameters'] = $outputType->getAgaviParameters($data[$outputTypeName]['parameters']);
			}
			$defaultOt = $cfg->getChild('output_types')->getAttribute('default');
		}

		if(!isset($data[$defaultOt])) {
			$error = 'Configuration file "%s" specifies undefined default Output Type "%s".';
			$error = sprintf($error, $document->documentURI, $defaultOt);
			throw new AgaviConfigurationException($error);
		}
		
		$code = [];
		foreach($data as $outputTypeName => $outputType) {
			$code[] = '$ot = new Agavi\Controller\AgaviOutputType();';
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