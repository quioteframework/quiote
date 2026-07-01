<?php
namespace Quiote\Config;

use Quiote\Util\Toolkit;

/**
 * ConfigHandler allows a developer to create a custom formatted
 * configuration file pertaining to any information they like and still
 * have it auto-generate PHP code.
 * @since      1.0.0
 * @deprecated Superseded by XmlConfigHandler, will be removed in Quiote 1.1
 * @version    1.0.0
 */
abstract class ConfigHandler extends BaseConfigHandler implements ILegacyConfigHandler
{
	/**
	 * @var        string An absolute filesystem path to a validation filename.
	 */
	protected $validationFile = null;

	/**
	 * @var        string A class name of the class which should be used to parse
	 *                    Input files of this config handler.
	 */
	protected $parser = null;
	
	/**
	 * Retrieve the parameter node values of the given item's parameters element.
	 * @param      ConfigValueHolder The node that contains a parameters child.
	 * @param      array             As associative array of parameters that will
	 *                               be overwritten if appropriate.
	 * @param      boolean           Whether or not values should be literalized.
	 * @return     array An associative array of parameters
	 * @since      1.0.0
	 */
	protected function getItemParameters($itemNode, $oldValues = [], $literalize = true)
	{
		$data = [];
		if($itemNode->hasChildren('parameters')) {
			foreach($itemNode->parameters as $node) {
				if(!$node->hasAttribute('name')) {
					// create a new entry in in the array and get they key of the new
					// created entry (the last in the array). The value doesn't matter
					// since it will be overwritten anyways
					$data[] = 0;
					$name = array_key_last($data);
				} else {
					$name = $node->getAttribute('name');
				}
				if($node->hasChildren('parameters')) {
					$data[$name] = (isset($oldValues[$name]) && is_array($oldValues[$name])) ? $oldValues[$name] : [];
					$data[$name] = $this->getItemParameters($node, $data[$name], $literalize);
				} else {
					$data[$name] = $literalize ? Toolkit::literalize($node->getValue()) : $node->getValue();
				}
			}
		}
		// we can NOT use array_merge here, since it would break numeric keys
		foreach($data as $key => $value) {
			$oldValues[$key] = $value;
		}
		return $oldValues;
	}

	/**
	 * Initialize this ConfigHandler.
	 * @param      string The path to a validation file for this config handler.
	 * @param      string The parser class to use.
	 * @param      array An associative array of initialization parameters.
	 * @throws     <b>InitializationException</b> If an error occurs while
	 *                                                 initializing the
	 *                                                 ConfigHandler
	 * @since      1.0.0
	 */
	public function initialize($validationFile = null, $parser = null, $parameters = [])
	{
		$this->validationFile = $validationFile;
		$this->parser = $parser;
		$this->setParameters($parameters);
	}
	
	/**
	 * Retrieves the stored validation filename.
	 * @return     string An absolute filesystem path to a validation filename.
	 * @since      1.0.0
	 */
	public function getValidationFile()
	{
		return $this->validationFile;
	}
	
	/**
	 * Builds a proper regular expression from the input pattern to test against
	 * the given subject. This is for "environment" and "context" attributes of
	 * configuration blocks in the files.
	 * @param      string A regular expression chunk without delimiters/anchors.
	 * @return     bool Whether or not the subject matched the pattern.
	 * @see        XmlConfigParser::testPattern()
	 * @since      1.0.0
	 */
	public static function testPattern($pattern, $subject)
	{
		return XmlConfigParser::testPattern($pattern, $subject);
	}

	/**
	 * Returns a properly ordered array of ConfigValueHolder configuration
	 * elements for given env and context.
	 * @param      ConfigValueHolder The root config element
	 * @param      string                 An environment name.
	 * @param      string                 A context name.
	 * @param      bool                   Whether the parser class should be
	 *                                    autoloaded or not.
	 * @return     array An array of ConfigValueHolder configuration elements.
	 * @since      1.0.0
	 */
	public function orderConfigurations(ConfigValueHolder $configurations, $environment = null, $context = null, $autoloadParser = true)
	{
		$configs = [];

		if($configurations->hasAttribute('parent')) {
			$parent = Toolkit::literalize($configurations->getAttribute('parent'));
			$parentConfigs = $this->orderConfigurations(ConfigCache::parseConfig($parent, $autoloadParser, $this->getValidationFile(), $this->parser)->configurations, $environment, $context, $autoloadParser);
			$configs = array_merge($configs, $parentConfigs);
		}

		foreach($configurations as $cfg) {
			if(!$cfg->hasAttribute('environment') && !$cfg->hasAttribute('context')) {
				$configs[] = $cfg;
			}
		}
		foreach($configurations as $cfg) {
			if($environment !== null && $cfg->hasAttribute('environment') && self::testPattern($cfg->getAttribute('environment'), $environment) && !$cfg->hasAttribute('context')) {
				$configs[] = $cfg;
			}
		}
		foreach($configurations as $cfg) {
			if(!$cfg->hasAttribute('environment') && $context !== null && $cfg->hasAttribute('context') && self::testPattern($cfg->getAttribute('context'), $context)) {
				$configs[] = $cfg;
			}
		}
		foreach($configurations as $cfg) {
			if($environment !== null && $cfg->hasAttribute('environment') && self::testPattern($cfg->getAttribute('environment'), $environment) && $context !== null && $cfg->hasAttribute('context') && self::testPattern($cfg->getAttribute('context'), $context)) {
				$configs[] = $cfg;
			}
		}

		return $configs;
	}
}

?>