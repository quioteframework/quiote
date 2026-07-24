<?php
namespace Quiote\Config;

use Quiote\Exception\ParseException;

/**
 * ConfigParser parses XML files using XmlConfigParser, but returns
 * old-style ConfigValueHolders.
 * @since      1.0.0
 * @deprecated Superseded by XmlConfigParser, will be removed in Quiote 1.1
 * @version    1.0.0
 */
class ConfigParser
{
	/**
	 * @var        string The encoding of the DOMDocument
	 */
	protected $encoding = 'utf-8';
	
	/**
	 * @var        string The filesystem path to the configuration file.
	 */
	protected $config = '';
	
	/**
	 * @param      string $config An absolute filesystem path to a configuration file.
	 * @param      ?string  $validationFile An associative array of validation information.
	 * @return     ConfigValueHolder The data handlers use to perform tasks.
	 * @since      1.0.0
	 */
	public function parse($config, $validationFile = null)
	{
		// copy path in case convertEncoding() needs to complain about a missing ICONV extension
		$this->config = $config;
		
		$parser = new XmlConfigParser($config, Config::getNullableString('core.environment'), null);
		
		$validation = [
			XmlConfigParser::STEP_TRANSFORMATIONS_BEFORE => [],
			XmlConfigParser::STEP_TRANSFORMATIONS_AFTER => [
				XmlConfigParser::VALIDATION_TYPE_XMLSCHEMA => [],
			],
		];
		if($validationFile !== null) {
			$validation[XmlConfigParser::STEP_TRANSFORMATIONS_AFTER][XmlConfigParser::VALIDATION_TYPE_XMLSCHEMA][] = $validationFile;
		}
		$doc = $parser->execute([], $validation);
		
		// remember encoding for convertEncoding()
		$this->encoding = strtolower((string) $doc->encoding);
		
		$rootRes = new ConfigValueHolder();
		
		if($doc->documentElement) {
			$this->parseNodes([$doc->documentElement], $rootRes);
		}
		
		return $rootRes;
	}

	/**
	 * Iterates through a list of nodes and stores to each node in the
	 * ConfigValueHolder
	 * @param      iterable<\DOMNode> $nodes An array or an object that can be iterated over
	 * @param      ConfigValueHolder $parentVh The storage for the info from the nodes
	 * @param      bool $isSingular Whether this list is the singular form of the parent node
	 * @return     void
	 * @since      1.0.0
	 */
	protected function parseNodes(iterable $nodes, ConfigValueHolder $parentVh, $isSingular = false)
	{
		foreach($nodes as $node) {
			if($node instanceof \DOMElement && !$node->namespaceURI) {
				$vh = new ConfigValueHolder();
				$nodeName = $this->convertEncoding($node->localName);
				$vh->setName($nodeName);
				$parentVh->addChildren($nodeName, $vh);

				foreach($node->attributes as $attribute) {
					if(!$attribute->namespaceURI) {
						$vh->setAttribute($this->convertEncoding($attribute->localName), $this->convertEncoding($attribute->nodeValue));
					}
				}

				// there are no child nodes so we set the node text contents as the value for the valueholder
				if($node->getElementsByTagName('*')->length == 0) {
					$vh->setValue($this->convertEncoding($node->nodeValue));
				}

				if($node->hasChildNodes()) {
					$this->parseNodes($node->childNodes, $vh);
				}
			}
		}
	}
	
	/**
	 * Handle encoding for a value, i.e. translate from UTF-8 if necessary.
	 * @param      ?string $value A UTF-8 string value from the DomDocument.
	 * @return     string A value in the correct encoding of the parsed document.
	 * @since      1.0.0
	 */
	protected function convertEncoding($value)
	{
		if($this->encoding == 'utf-8') {
			return (string) $value;
		} elseif($this->encoding == 'iso-8859-1') {
			return mb_convert_encoding((string) $value, 'ISO-8859-1');
		} elseif(function_exists('iconv')) {
			$converted = iconv('UTF-8', $this->encoding, (string) $value);
			if($converted === false) {
				throw new ParseException('Failed to convert configuration value to encoding "' . $this->encoding . '" for configuration file "' . $this->config . '".');
			}
			return $converted;
		} else {
			throw new ParseException('No iconv module available, configuration file "' . $this->config . '" with input encoding "' . $this->encoding . '" cannot be parsed.');
		}
	}
}

?>