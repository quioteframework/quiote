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

/**
 * AgaviFactoryConfigHandler allows you to specify which factory implementation 
 * the system will use.
 *
 * @package    agavi
 * @subpackage config
 *
 * @author     David Zülke <dz@bitxtender.com>
 * @author     Dominik del Bondio <ddb@bitxtender.com>
 * @author     Noah Fontes <noah.fontes@bitextender.com>
 * @copyright  Authors
 * @copyright  The Agavi Project
 *
 * @since      0.9.0
 *
 * @version    $Id$
 */
class AgaviFactoryConfigHandler extends AgaviXmlConfigHandler
{
	const XML_NAMESPACE = 'http://agavi.org/agavi/config/parts/factories/1.1';
	
	/**
	 * Execute this configuration handler.
	 *
	 * @param      AgaviXmlConfigDomDocument The document to parse.
	 *
	 * @return     string Data to be written to a cache file.
	 *
	 * @throws     <b>AgaviParseException</b> If a requested configuration file is
	 *                                        improperly formatted.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @author     Noah Fontes <noah.fontes@bitextender.com>
	 * @since      0.11.0
	 */
	public function execute(AgaviXmlConfigDomDocument $document) : string
	{
		// set up our default namespace
		$document->setDefaultNamespace(self::XML_NAMESPACE, 'factories');
		
		$config = $document->documentURI;
		$data = [];
		
		// The order of this initialization code is fixed, to not change
		// name => required?
		$factories = [
			// Validation manager remains a required factory (middleware replaces filters)
			'validation_manager' => [
				'required' => true,
				'var' => null,
				'must_implement' => [],
			],
			// Response factory info (global response instance)
			'response' => [
				'required' => true,
				'var' => null,
				'must_implement' => [],
			],
			// Order: database manager must be instantiated (and startup run) BEFORE storage & user.
			'database_manager' => [
				'required' => true,
				'var' => 'databaseManager',
				'must_implement' => [],
			],
			'database_manager', // startup()
			'logger_manager' => [
				'required' => AgaviConfig::get('core.use_logging', false),
				'var' => 'loggerManager',
				'must_implement' => [],
			],
			'logger_manager', // startup()
			'translation_manager' => [
				'required' => AgaviConfig::get('core.use_translation', false),
				'var' => 'translationManager',
				'must_implement' => [],
			],
			'routing' => [
				'required' => true,
				'var' => 'routing',
				'must_implement' => [],
			],
			// Ensure a legacy request object exists for templates/views and worker-mode recreation
			'request' => [
				'required' => true,
				'var' => 'request',
				'must_implement' => [],
			],
			'controller' => [
				'required' => true,
				'var' => 'controller',
				'must_implement' => [],
			],
			'storage' => [
				'required' => true,
				'var' => 'storage',
				'must_implement' => [],
			],
			'storage', // startup()
			'user' => [
				'required' => true,
				'var' => 'user',
				'must_implement' => [],
			],
			'translation_manager', // startup()
			'user', // startup()
			'routing', // startup()
			'controller', // startup()
		];
		
		foreach($document->getConfigurationElements() as $configuration) {
			foreach($factories as $factory => $info) {
				if(is_array($info) && $info['required'] && $configuration->hasChild($factory)) {
					$element = $configuration->getChild($factory);
					
					$data[$factory] ??= ['class' => null, 'params' => []];
					$data[$factory]['class'] = $element->getAttribute('class', $data[$factory]['class']);
					$data[$factory]['params'] = $element->getAgaviParameters($data[$factory]['params']);
				}
			}
		}
		
		$code = [];
		$shutdownSequence = [];
		
		foreach($factories as $factory => $info) {
			if(is_array($info)) {
				$required = $info['required'];
				
				if(!$required) {
					continue;
				}
				
				if(!isset($data[$factory]) || $data[$factory]['class'] === null) {
					$error = 'Configuration file "%s" has missing or incomplete entry "%s"';
					$error = sprintf($error, $config, $factory);
					throw new AgaviConfigurationException($error);
				}
				
				try {
					$rc = new \ReflectionClass($data[$factory]['class']);
				} catch(\ReflectionException $e) {
					$error = 'Configuration file "%s" specifies unknown class "%s" for entry "%s"';
					$error = sprintf($error, $config, $data[$factory]['class'], $factory);
					throw new AgaviConfigurationException($error, 0,  $e);
				}
				foreach($info['must_implement'] as $interface) {
					if(!$rc->implementsInterface($interface)) {
						$error = 'Class "%s" for entry "%s" does not implement interface "%s" in configuration file "%s"';
						$error = sprintf($error, $data[$factory]['class'], $factory, $interface, $config);
						throw new AgaviConfigurationException($error);
					}
				}
				
				if($info['var'] !== null) {
					// we have to make an instance
					$code[] = sprintf(
						'$this->%1$s = new %2$s();' . "\n" . '$this->%1$s->initialize($this, %3$s);',
						$info['var'],
						$data[$factory]['class'],
						var_export($data[$factory]['params'], true)
					);
					// Capture factory info immediately for worker-mode lazy recreation safety (now for all var-based factories)
					$code[] = sprintf(
						'$this->%1$sFactoryInfo = [\'class\' => %2$s, \'parameters\' => %3$s];',
						$info['var'],
						var_export($data[$factory]['class'], true),
						var_export($data[$factory]['params'], true)
					);
				} else {
					// it's a factory info
					$code[] = sprintf(
						'$this->factories[%1$s] = %2$s;',
						var_export($factory, true),
						var_export([
							'class' => $data[$factory]['class'],
							'parameters' => $data[$factory]['params'],
						], true)
					);
					// Provide explicit factory info array for compatibility with createInstanceFor() callers
					$code[] = sprintf(
						'$this->factories[%1$s][\'factory_info\'] = %2$s;',
						var_export($factory, true),
						var_export([
							'class' => $data[$factory]['class'],
							'parameters' => $data[$factory]['params'],
						], true)
					);
				}
				
				// No close conditional block needed
			} else {
				// Handle startup calls
				$varName = $factories[$info]['var'];
				$required = $factories[$info]['required'];
				
				if($required) {
					$code[] = sprintf('$this->%s->startup();', $varName);
					array_unshift($shutdownSequence, sprintf('$this->%s', $varName));
				}
			}
		}
		
		// Set the shutdown sequence
		$code[] = sprintf('$this->shutdownSequence = [%s];', implode(",\n", $shutdownSequence));
		
		return $this->generate($code, $config);
	}
}

?>