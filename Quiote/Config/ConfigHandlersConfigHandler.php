<?php
namespace Quiote\Config;

use Quiote\Config\Util\DOM\XmlConfigDomDocument;
use Quiote\Exception\QuioteException;
use Quiote\Util\Toolkit;

/**
 * ConfigHandlersConfigHandler allows you to specify configuration handlers
 * for the application or on a module level.
 * @since      1.0.0
 * @version    1.0.0
 */
class ConfigHandlersConfigHandler extends XmlConfigHandler
{
	const XML_NAMESPACE = 'http://quiote.dev/quiote/config/parts/config_handlers/1.1';
	
	/**
	 * Execute this configuration handler.
	 * @param      XmlConfigDomDocument The document to handle.
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

				$category = Toolkit::normalizePath(Toolkit::expandDirectives($pattern));

				$class = $handler->getAttribute('class');

				$transformations = [
					XmlConfigParser::STAGE_SINGLE => [],
					XmlConfigParser::STAGE_COMPILATION => [],
				];
				if($handler->has('transformations')) {
					foreach($handler->get('transformations') as $transformation) {
						$path = Toolkit::literalize($transformation->getValue());
						$for = $transformation->getAttribute('for', XmlConfigParser::STAGE_SINGLE);
						$transformations[$for][] = $path;
					}
				}

				$validations = [
					XmlConfigParser::STAGE_SINGLE => [
						XmlConfigParser::STEP_TRANSFORMATIONS_BEFORE => [
							XmlConfigParser::VALIDATION_TYPE_RELAXNG => [
							],
							XmlConfigParser::VALIDATION_TYPE_SCHEMATRON => [
							],
							XmlConfigParser::VALIDATION_TYPE_XMLSCHEMA => [
							],
						],
						XmlConfigParser::STEP_TRANSFORMATIONS_AFTER => [
							XmlConfigParser::VALIDATION_TYPE_RELAXNG => [
							],
							XmlConfigParser::VALIDATION_TYPE_SCHEMATRON => [
							],
							XmlConfigParser::VALIDATION_TYPE_XMLSCHEMA => [
							],
						],
					],
					XmlConfigParser::STAGE_COMPILATION => [
						XmlConfigParser::STEP_TRANSFORMATIONS_BEFORE => [
							XmlConfigParser::VALIDATION_TYPE_RELAXNG => [
							],
							XmlConfigParser::VALIDATION_TYPE_SCHEMATRON => [
							],
							XmlConfigParser::VALIDATION_TYPE_XMLSCHEMA => [
							],
						],
						XmlConfigParser::STEP_TRANSFORMATIONS_AFTER => [
							XmlConfigParser::VALIDATION_TYPE_RELAXNG => [
							],
							XmlConfigParser::VALIDATION_TYPE_SCHEMATRON => [
							],
							XmlConfigParser::VALIDATION_TYPE_XMLSCHEMA => [
							],
						],
					],
				];
				if($handler->has('validations')) {
					foreach($handler->get('validations') as $validation) {
						$path = Toolkit::literalize($validation->getValue());
						$type = null;
						if(!$validation->hasAttribute('type')) {
							$type = $this->guessValidationType($path);
						} else {
							$type = $validation->getAttribute('type');
						}
						$for = $validation->getAttribute('for', XmlConfigParser::STAGE_SINGLE);
						$step = $validation->getAttribute('step', XmlConfigParser::STEP_TRANSFORMATIONS_AFTER);
						$validations[$for][$step][$type][] = $path;
					}
				}

				$handlers[$category] ??= [
						'parameters' => [],
						];
				$handlers[$category] = [
					'class' => $class,
					'parameters' => $handler->getQuioteParameters($handlers[$category]['parameters']),
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
	 * @param      string The path to the file.
	 * @return     string An XmlConfigParser::VALIDATION_TYPE_* const value.
	 * @throws     Exception If the type could not be determined.
	 * @since      1.0.0
	 */
	protected function guessValidationType($path)
	{
		return match (pathinfo((string) $path, PATHINFO_EXTENSION)) {
            'rng' => XmlConfigParser::VALIDATION_TYPE_RELAXNG,
            'rnc' => XmlConfigParser::VALIDATION_TYPE_RELAXNG,
            'sch' => XmlConfigParser::VALIDATION_TYPE_SCHEMATRON,
            'xsd' => XmlConfigParser::VALIDATION_TYPE_XMLSCHEMA,
            default => throw new QuioteException(sprintf('Could not determine validation type for file "%s"', $path)),
        };
	}
}

?>