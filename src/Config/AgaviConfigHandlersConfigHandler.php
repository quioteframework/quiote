<?php

// +---------------------------------------------------------------------------+
// | This file is part of the Agavi package.                                   |
// | Copyright (c) 2005-2011 the Agavi Project.                                |
// | Based on the Mojavi3 MVC Framework, Copyright (c) 2003-2005 Sean Kerr.    |
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
use Agavi\Exception\AgaviException;
use Agavi\Util\AgaviToolkit;

/**
 * AgaviConfigHandlersConfigHandler allows you to specify configuration handlers
 * for the application or on a module level.
 *
 * @package    agavi
 * @subpackage config
 *
 * @author     Dominik del Bondio <ddb@bitxtender.com>
 * @author     Noah Fontes <noah.fontes@bitextender.com>
 * @author     David Zülke <david.zuelke@bitextender.com>
 * @copyright  Authors
 * @copyright  The Agavi Project
 *
 * @since      0.11.0
 *
 * @version    $Id$
 */
class AgaviConfigHandlersConfigHandler extends AgaviXmlConfigHandler
{
	const XML_NAMESPACE = 'http://agavi.org/agavi/config/parts/config_handlers/1.1';
	
	/**
	 * Execute this configuration handler.
	 *
	 * @param      AgaviXmlConfigDomDocument The document to handle.
	 *
	 * @return     string Data to be written to a cache file.
	 *
	 * @throws     <b>AgaviUnreadableException</b> If a requested configuration
	 *                                             file does not exist or is not
	 *                                             readable.
	 * @throws     <b>AgaviParseException</b> If a requested configuration file is
	 *                                        improperly formatted.
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @author     Noah Fontes <noah.fontes@bitextender.com>
	 * @author     David Zülke <david.zuelke@bitextender.com>
	 * @since      0.11.0
	 */
	public function execute(AgaviXmlConfigDomDocument $document) : string
	{
		// set up our default namespace
		$document->setDefaultNamespace(self::XML_NAMESPACE, 'config_handlers');
		
		// init our data arrays
		$handlers = [];
		$middlewareEnabledMap = [];
		
		foreach($document->getConfigurationElements() as $configuration) {
			// Capture middleware_config irrespective of handlers presence
			if($configuration->has('middleware_config')) {
				foreach($configuration->get('middleware_config') as $mwConfig) {
					foreach($mwConfig->get('middleware') as $mw) {
						$class = $mw->getAttribute('class');
						$enabledAttr = strtolower((string)$mw->getAttribute('enabled', 'true'));
						$enabled = !in_array($enabledAttr, ['0','false','off','no'], true);
						$middlewareEnabledMap[$class] = $enabled;
					}
				}
			}
			if(!$configuration->has('handlers')) {
				continue;
			}
			
			// let's do our fancy work
			foreach($configuration->get('handlers') as $handler) {
				$pattern = $handler->getAttribute('pattern');
				
				$category = AgaviToolkit::normalizePath(AgaviToolkit::expandDirectives($pattern));
				
				$class = $handler->getAttribute('class');
				
				$transformations = [
					AgaviXmlConfigParser::STAGE_SINGLE => [],
					AgaviXmlConfigParser::STAGE_COMPILATION => [],
				];
				if($handler->has('transformations')) {
					foreach($handler->get('transformations') as $transformation) {
						$path = AgaviToolkit::literalize($transformation->getValue());
						$for = $transformation->getAttribute('for', AgaviXmlConfigParser::STAGE_SINGLE);
						$transformations[$for][] = $path;
					}
				}
				
				$validations = [
					AgaviXmlConfigParser::STAGE_SINGLE => [
						AgaviXmlConfigParser::STEP_TRANSFORMATIONS_BEFORE => [
							AgaviXmlConfigParser::VALIDATION_TYPE_RELAXNG => [
							],
							AgaviXmlConfigParser::VALIDATION_TYPE_SCHEMATRON => [
							],
							AgaviXmlConfigParser::VALIDATION_TYPE_XMLSCHEMA => [
							],
						],
						AgaviXmlConfigParser::STEP_TRANSFORMATIONS_AFTER => [
							AgaviXmlConfigParser::VALIDATION_TYPE_RELAXNG => [
							],
							AgaviXmlConfigParser::VALIDATION_TYPE_SCHEMATRON => [
							],
							AgaviXmlConfigParser::VALIDATION_TYPE_XMLSCHEMA => [
							],
						],
					],
					AgaviXmlConfigParser::STAGE_COMPILATION => [
						AgaviXmlConfigParser::STEP_TRANSFORMATIONS_BEFORE => [
							AgaviXmlConfigParser::VALIDATION_TYPE_RELAXNG => [
							],
							AgaviXmlConfigParser::VALIDATION_TYPE_SCHEMATRON => [
							],
							AgaviXmlConfigParser::VALIDATION_TYPE_XMLSCHEMA => [
							],
						],
						AgaviXmlConfigParser::STEP_TRANSFORMATIONS_AFTER => [
							AgaviXmlConfigParser::VALIDATION_TYPE_RELAXNG => [
							],
							AgaviXmlConfigParser::VALIDATION_TYPE_SCHEMATRON => [
							],
							AgaviXmlConfigParser::VALIDATION_TYPE_XMLSCHEMA => [
							],
						],
					],
				];
				if($handler->has('validations')) {
					foreach($handler->get('validations') as $validation) {
						$path = AgaviToolkit::literalize($validation->getValue());
						$type = null;
						if(!$validation->hasAttribute('type')) {
							$type = $this->guessValidationType($path);
						} else {
							$type = $validation->getAttribute('type');
						}
						$for = $validation->getAttribute('for', AgaviXmlConfigParser::STAGE_SINGLE);
						$step = $validation->getAttribute('step', AgaviXmlConfigParser::STEP_TRANSFORMATIONS_AFTER);
						$validations[$for][$step][$type][] = $path;
					}
				}
				
				$handlers[$category] ??= [
						'parameters' => [],
						];
				$handlers[$category] = [
					'class' => $class,
					'parameters' => $handler->getAgaviParameters($handlers[$category]['parameters']),
					'transformations' => $transformations,
					'validations' => $validations,
				];
			}
			// also re-process middleware_config inside same configuration (already handled above)
		}
		
		// Expose middleware enable map under reserved key so bootstrap can import it
		if($middlewareEnabledMap) {
			$handlers['__middleware_config'] = $middlewareEnabledMap;
		}
		$data = [ 'return ' . var_export($handlers, true), ];
		
		return $this->generate($data, $document->documentURI);
	}
	
	/**
	 * Convenience method to quickly guess the type of a validation file using its
	 * file extension.
	 *
	 * @param      string The path to the file.
	 *
	 * @return     string An AgaviXmlConfigParser::VALIDATION_TYPE_* const value.
	 *
	 * @throws     AgaviException If the type could not be determined.
	 *
	 * @author     David Zülke <david.zuelke@bitextender.com>
	 * @since      1.0.0
	 */
	protected function guessValidationType($path)
	{
		return match (pathinfo((string) $path, PATHINFO_EXTENSION)) {
            'rng' => AgaviXmlConfigParser::VALIDATION_TYPE_RELAXNG,
            'rnc' => AgaviXmlConfigParser::VALIDATION_TYPE_RELAXNG,
            'sch' => AgaviXmlConfigParser::VALIDATION_TYPE_SCHEMATRON,
            'xsd' => AgaviXmlConfigParser::VALIDATION_TYPE_XMLSCHEMA,
            default => throw new AgaviException(sprintf('Could not determine validation type for file "%s"', $path)),
        };
	}
}

?>