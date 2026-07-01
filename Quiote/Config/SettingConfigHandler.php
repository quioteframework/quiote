<?php
namespace Quiote\Config;

use Quiote\Config\Util\DOM\XmlConfigDomDocument;
use Quiote\Exception\ConfigurationException;
use Quiote\Util\Toolkit;

/**
 * SettingConfigHandler handles the settings.xml file
 * @since      1.0.0
 * @version    1.0.0
 */
class SettingConfigHandler extends XmlConfigHandler
{
	const XML_NAMESPACE = 'http://quiote.dev/quiote/config/parts/settings/1.1';
	
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
		$document->setDefaultNamespace(self::XML_NAMESPACE, 'settings');
		
		// init our data array
		$data = [];
		
		$prefix = 'core.';
		
		foreach($document->getConfigurationElements() as $cfg) {
			// let's do our fancy work
			if($cfg->has('system_actions')) {
				foreach($cfg->get('system_actions') as $action) {
					$name = $action->getAttribute('name');
					$data[sprintf('actions.%s_module', $name)] = $action->getChild('module')->getValue();
					$data[sprintf('actions.%s_action', $name)] = $action->getChild('action')->getValue();
				}
			}
			
			// loop over <setting> elements; there can be many of them
			foreach($cfg->get('settings') as $setting) {
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
					$data[$settingName] = $setting->getLiteralValue();
				}
			}
			
			if($cfg->has('exception_templates')) {
				foreach($cfg->get('exception_templates') as $exception_template) {
					$tpl = Toolkit::expandDirectives($exception_template->getValue());
					if(!is_readable($tpl)) {
						throw new ConfigurationException('Exception template "' . $tpl . '" does not exist or is unreadable');
					}
					if($exception_template->hasAttribute('context')) {
						foreach(array_map(trim(...), explode(' ', (string) $exception_template->getAttribute('context'))) as $ctx) {
							$data['exception.templates.' . $ctx] = $tpl;
						}
					} else {
						$data['exception.default_template'] = Toolkit::expandDirectives($tpl);
					}
				}
			}
		}

		$code = 'Quiote\\Config\\Config::fromArray(' . var_export($data, true) . ');';

		return $this->generate($code, $document->documentURI);
	}
}

?>