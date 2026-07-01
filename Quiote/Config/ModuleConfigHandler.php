<?php
namespace Quiote\Config;

use Quiote\Config\Util\DOM\XmlConfigDomDocument;
use Quiote\Util\Toolkit;

/**
 * ModuleConfigHandler reads module configuration files to determine the
 * status of a module.
 * @since      1.0.0
 * @version    1.0.0
 */
class ModuleConfigHandler extends XmlConfigHandler
{
	const XML_NAMESPACE = 'http://quiote.dev/quiote/config/parts/module/1.1';
	
	/**
	 * Execute this configuration handler.
	 * @param      XmlConfigDomDocument The document to parse.
	 * @return     string Data to be written to a cache file.
	 * @throws     <b>ParseException</b> If a requested configuration file is
	 *                                        improperly formatted.
	 * @since      1.0.0
	 */
	public function execute(XmlConfigDomDocument $document) : string
	{
		// set up our default namespace
		$document->setDefaultNamespace(self::XML_NAMESPACE, 'module');
		
		// remember the config file path
		$config = $document->documentURI;
		
		$enabled = false;
		$prefix = 'modules.${moduleName}.';
		$data = [];
		
		// loop over <configuration> elements
		foreach($document->getConfigurationElements() as $configuration) {
			$module = $configuration->getChild('module');
			if(!$module) {
				continue;
			}
			
			// enabled flag is treated separately
			$enabled = (bool) Toolkit::literalize($module->getAttribute('enabled'));
			
			// loop over <setting> elements; there can be many of them
			foreach($module->get('settings') as $setting) {
				$localPrefix = $prefix;
				
				// let's see if this buddy has a <settings> parent with valuable information
				if($setting->parentNode->localName == 'settings') {
					if($setting->parentNode->hasAttribute('prefix')) {
						$localPrefix = $setting->parentNode->getAttribute('prefix');
					}
				}
				
				$settingName = $localPrefix . $setting->getAttribute('name');
				if($setting->hasQuioteParameters()) {
					$data[$settingName] = $setting->getQuioteParameters();
				} else {
					$data[$settingName] = Toolkit::literalize($setting->getValue());
				}
			}
		}
		
		$code = [];
		$code[] = '$lcModuleName = strtolower($moduleName);';
		$code[] = 'Quiote\Config\Config::set(Quiote\Util\Toolkit::expandVariables(' . var_export($prefix . 'enabled', true) . ', array(\'moduleName\' => $lcModuleName)), ' . var_export($enabled, true) . ', true, true);';
		if(count($data)) {
			$code[] = '$moduleConfig = ' . var_export($data, true) . ';';
			$code[] = '$moduleConfigKeys = array_keys($moduleConfig);';
			$code[] = 'foreach($moduleConfigKeys as &$value) $value = Quiote\Util\Toolkit::expandVariables($value, array(\'moduleName\' => $lcModuleName));';
			$code[] = '$moduleConfig = array_combine($moduleConfigKeys, $moduleConfig);';
			$code[] = 'Quiote\Config\Config::fromArray($moduleConfig);';
		}
		
		return $this->generate($code, $config);
	}
}

?>