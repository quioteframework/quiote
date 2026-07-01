<?php
namespace Quiote\Config;

use Quiote\Config\Util\DOM\XmlConfigDomDocument;
use Quiote\Config\Util\DOM\XmlConfigDomElement;
use Quiote\Util\Inflector;
use Quiote\Util\Toolkit;

/**
 * ReturnArrayConfigHandler allows you to retrieve the contents of a config
 * file as an array.
 * Assumes that the content elements are in no XML namespace; if you want to use
 * an XML namespace for your elements, define the namespace URI using the
 * "namespace_uri" parameter.
 * @since      1.0.0
 * @version    1.0.0
 */
class ReturnArrayConfigHandler extends XmlConfigHandler
{
	/**
	 * Execute this configuration handler.
	 * @param      XmlConfigDomDocument The document to parse.
	 * @return     string Data to be written to a cache file.
	 * @since      1.0.0
	 */
	public function execute(XmlConfigDomDocument $document) : string
	{
		$document->setDefaultNamespace($this->getParameter('namespace_uri', ''));
		
		$data = [];
		foreach($document->getConfigurationElements() as $cfg) {
			$data = array_merge($data, $this->convertToArray($cfg, true));
		}
		
		// compile data
		$code = 'return ' . var_export($data, true) . ';';
		
		return $this->generate($code, $document->documentURI);
	}

	/**
	 * Converts an XmlConfigDomElement into an array.
	 * @param      XmlConfigDomElement The configuration element to convert.
	 * @param      bool                     Whether this is a top level element.
	 * @return     array The configuration values as an array.
	 * @since      1.0.0
	 */
	protected function convertToArray(XmlConfigDomElement $item, $topLevel = false)
	{
		$idAttribute = $this->getParameter('id_attribute', 'name');
		$valueKey = $this->getParameter('value_key', 'value');
		$forceArrayValues = $this->getParameter('force_array_values', false);
		$attributePrefix = $this->getParameter('attribute_prefix', '');
		$literalize = $this->getParameter('literalize', true);
		
		$singularParentName = Inflector::singularize($item->getName());

		$data = [];

		$attribs = $item->getAttributes();
		$numAttribs = count($attribs);
		if($idAttribute && $item->hasAttribute($idAttribute)) {
			$numAttribs--;
		}
		
		foreach($item->getAttributes() as $name => $value) {
			if(($topLevel && in_array($name, ['context', 'environment'])) || $name == $idAttribute) {
				continue;
			}

			if($literalize) {
				$value = Toolkit::literalize($value);
			}

			if(!isset($data[$name])) {
				$data[$attributePrefix . $name] = $value;
			}
		}
		
		if(!(int)$item->ownerDocument->getXpath()->evaluate(sprintf('count(*[namespace-uri() = "%s"])', $item->ownerDocument->getDefaultNamespaceUri()), $item)) {
			if($literalize) {
				$val = $item->getLiteralValue();
			} else {
				$val = $item->getValue();
			}
			
			if($val === null) {
				$val = '';
			}
			
			if(!$topLevel && ($numAttribs || $forceArrayValues)) {
				$data[$valueKey] = $val;
			} elseif(!$topLevel) {
				$data = $val;
			}
			
		} else {
			$names = [];
			$children = $item->ownerDocument->getXpath()->query(sprintf('*[namespace-uri() = "%s"]', $item->ownerDocument->getDefaultNamespaceUri()), $item);
			foreach($children as $child) {
				$names[] = $child->getName();
			}
			$dupes = [];
			foreach(array_unique(array_diff_assoc($names, array_unique($names))) as $name) {
				$dupes[] = $name;
			}
			foreach($children as $key => $child) {
				$hasId = ($idAttribute && $child->hasAttribute($idAttribute));
				$isDupe = in_array($child->getName(), $dupes);
				$hasParent = $child->getName() == $singularParentName && $item->getName() != $singularParentName;
				if(($hasId || $isDupe) && !$hasParent) {
					// it's one of multiple tags in this level without the respective plural form as the parent node
					if(!isset($data[$idx = Inflector::pluralize($child->getName())])) {
						$data[$idx] = [];
					}
					$hasParent = true;
					$to =& $data[$idx];
				} else {
					$to =& $data;
				}
				
				if($hasId) {
					$key = $child->getAttribute($idAttribute);
					if($literalize) {
						// no literalize, just constants!
						$key = Toolkit::expandDirectives($key);
					}
					$to[$key] = $this->convertToArray($child);
				} elseif($hasParent) {
					$to[] = $this->convertToArray($child);
				} else {
					$to[$child->getName()] = $this->convertToArray($child);
				}
			}
		}
		
		return $data;
	}
}
?>