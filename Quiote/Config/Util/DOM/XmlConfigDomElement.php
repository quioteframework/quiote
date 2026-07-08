<?php
namespace Quiote\Config\Util\DOM;

use Quiote\Config\XmlConfigParser;
use Quiote\Util\Inflector;
use Quiote\Util\Toolkit;
use ReturnTypeWillChange;
use Traversable;

/**
 * Extended DOMElement class with several convenience enhancements.
 * The owner document of any node in this DOM tree is always an
 * XmlConfigDomDocument: XmlConfigDomDocument::__construct() registers Quiote's
 * node classes via registerNodeClass() for every DOM node type, including
 * DOMDocument itself, so $ownerDocument is never a vanilla DOMDocument.
 * @property-read XmlConfigDomDocument $ownerDocument
 * @implements \IteratorAggregate<int,XmlConfigDomElement>
 * @since      1.0.0
 * @version    1.0.0
 */
class XmlConfigDomElement extends \DOMElement implements \IteratorAggregate, \Stringable
{
	/**
	 * __toString() magic method, returns the element value.
	 * @see        XmlConfigDomElement::getValue()
	 * @return     string The element value.
	 * @since      1.0.0
	 */
	public function __toString(): string
	{
		return $this->getValue();
	}
	
	/**
	 * Returns the element name.
	 * @return     string The element name.
	 * @since      1.0.0
	 */
	public function getName(): string
	{
		// what to return here? name with prefix? no.
		// but... element name, or with ns prefix?
		return $this->nodeName;
	}
	
	/**
	 * Returns the element value.
	 * @return     string The element value.
	 * @since      1.0.0
	 */
	public function getValue(): string
	{
		// TODO: or textContent?
		// trimmed or not? in utf-8 or native encoding?
		// I'd really say we only support utf-8 for the new api
		// nodeValue is declared nullable on DOMNode in general, but for an
		// element node it is always the concatenated text content, which is
		// never null (empty string at worst).
		return $this->nodeValue ?? '';
	}
	
	/**
	 * Returns the literal value. By default, that means whitespace is trimmed,
	 * boolean literals ("on", "yes", "true", "no", "off", "false") are converted
	 * and configuration directives ("%core.app_dir%") are expanded.
	 * Takes attributes {http://www.w3.org/XML/1998/namespace}space and
	 * {http://quiote.dev/quiote/config/global/envelope/1.1}literalize into account
	 * when computing the literal value. This way, users can control the trimming
	 * and the literalization of values.
	 * AEP-100 has a list of all the conversion rules that apply.
	 * @return     mixed The element content converted according to the rules
	 *                   defined in AEP-100.
	 * @since      1.0.0
	 */
	public function getLiteralValue(): mixed
	{
		$value = $this->getValue();
		// XML specifies [\x9\xA\xD\x20] as whitespace
		// trim strips more than that
		// no problem though, because these other chars aren't legal in XML
		$trimmedValue = trim($value);
		
		$preserveWhitespace = $this->getAttributeNS(XmlConfigParser::NAMESPACE_XML_1998, 'space') == 'preserve';
		$literalize = Toolkit::literalize($this->getAttributeNS(XmlConfigParser::NAMESPACE_QUIOTE_ENVELOPE_LATEST, 'literalize')) !== false;
		
		if($literalize) {
			if($preserveWhitespace && ($trimmedValue === '' || $value != $trimmedValue)) {
				// we must preserve whitespace, and there is leading or trailing whitespace in the original value, so we won't run Toolkit::literalize(), which trims the input and then converts "true" to a boolean and so forth
				// however, we should still expand possible occurrences of config directives
				$value = Toolkit::expandDirectives($value);
			} else {
				// no need to preserve whitespace, or no leading/trailing whitespace, which means we can expand "true", "false" and so forth using Toolkit::literalize()
				$value = Toolkit::literalize($trimmedValue);
			}
		} elseif(!$preserveWhitespace) {
			$value = $trimmedValue;
			if($value === '') {
				// with or without literalize, an empty string must be converted to NULL if xml:space is default (see ticket #1203 and AEP-100)
				$value = null;
			}
		}
		
		return $value;
	}
	
	/**
	 * Returns an iterator for the child nodes.
	 * @return     \Traversable<int,XmlConfigDomElement> An iterator.
	 * @since      1.0.0
	 */
	public function getIterator(): Traversable
	{
		// should only pull elements from the default ns
		$prefix = $this->ownerDocument->getDefaultNamespacePrefix();
		if($prefix) {
			$result = $this->ownerDocument->query(sprintf('child::%s:*', $prefix), $this);
		} else {
			$result = $this->ownerDocument->query('child::*', $this);
		}
		// registerNodeClass() guarantees element nodes are always XmlConfigDomElement.
		foreach ($result as $node) {
			if ($node instanceof self) {
				yield $node;
			}
		}
	}
	
	/**
	 * Retrieve singular form of given element name.
	 * This does special splitting only of the last part of the name if the name
	 * of the element contains hyphens, underscores or dots.
	 * @param      string $name The element name to singularize.
	 * @return     string The singularized element name.
	 * @since      1.0.0
	 */
	protected function singularize($name): string
	{
		// TODO: shouldn't this be static?
		$names = preg_split('#([_\-\.])#', (string) $name, -1, PREG_SPLIT_DELIM_CAPTURE);
		if ($names === false) {
			throw new \LogicException(sprintf('Failed to split element name "%s" into singular/plural parts.', $name));
		}
		$lastIndex = count($names) - 1;
		$names[$lastIndex] = Inflector::singularize($names[$lastIndex]);
		return implode('', $names);
	}
	
	/**
	 * Convenience method to retrieve child elements of the given name.
	 * Accepts singular or plural forms of the name, and will detect and handle
	 * parent containers with plural names properly.
	 * @param      string $name The name of the element(s) to check for.
	 * @param      string $namespaceUri The namespace URI. If null, the document default
	 *                    namespace will be used. If an empty string, no namespace
	 *                    will be used.
	 * @return     array<int, XmlConfigDomElement> A list of the child elements.
	 * @since      1.0.0
	 */
	public function get($name, $namespaceUri = null): array
	{
		return $this->getChildren($name, $namespaceUri, true);
	}
	
	/**
	 * Convenience method to check if there are child elements of the given name.
	 * Accepts singular or plural forms of the name, and will detect and handle
	 * parent containers with plural names properly.
	 * @param      string $name The name of the element(s) to check for.
	 * @param      string $namespaceUri The namespace URI. If null, the document default
	 *                    namespace will be used. If an empty string, no namespace
	 *                    will be used.
	 * @return     bool True if one or more child elements with the given name
	 *                  exist, false otherwise.
	 * @since      1.0.0
	 */
	public function has($name, $namespaceUri = null): bool
	{
		return $this->hasChildren($name, $namespaceUri, true);
	}
	
	/**
	 * Count the number of child elements with a given name.
	 * @param      string $name The name of the element.
	 * @param      string $namespaceUri The namespace URI. If null, the document default
	 *                    namespace will be used. If an empty string, no namespace
	 *                    will be used.
	 * @param      bool $pluralMagic Whether or not to apply automatic singular/plural
	 *                    handling that skips plural container elements.
	 * @return     int The number of child elements with the given name.
	 * @since      1.0.0
	 */
	public function countChildren($name, $namespaceUri = null, $pluralMagic = false): int
	{
		// if arg is null, then only check for elements from our default namespace
		// if namespace uri is null, use default ns. if empty string, use no ns
		$namespaceUri ??= $this->ownerDocument->getDefaultNamespaceUri();
		
		// init our vars
		$query = '';
		$singularName = null;
		
		// tag our element, because older libxmls will mess things up otherwise
		// http://trac.quiote.org/ticket/1039
		$marker = uniqid('', true);
		$this->setAttributeNS(XmlConfigParser::NAMESPACE_QUIOTE_ANNOTATIONS_LATEST, 'quiote_annotations_latest:marker', $marker);
		
		if($pluralMagic) {
			// we always assume that we either get plural names, or the singular of the singular is not different from the singular :)
			$singularName = $this->singularize($name);
			if($namespaceUri) {
				$query = 'count(child::*[local-name() = "%2$s" and namespace-uri() = "%3$s" and ../@quiote_annotations_latest:marker = "%4$s"]) + count(child::*[local-name() = "%1$s" and namespace-uri() = "%3$s" and ../@quiote_annotations_latest:marker = "%4$s"]/*[local-name() = "%2$s" and namespace-uri() = "%3$s" and ../../@quiote_annotations_latest:marker = "%4$s"])';
			} else {
				$query = 'count(%1$s[../@quiote_annotations_latest:marker = "%4$s"]/%2$s[../../@quiote_annotations_latest:marker = "%4$s"]) + count(%2$s[../@quiote_annotations_latest:marker = "%4$s"])';
			}
		} else {
			if($namespaceUri) {
				$query = 'count(child::*[local-name() = "%1$s" and namespace-uri() = "%3$s" and ../@quiote_annotations_latest:marker = "%4$s"])';
			} else {
				$query = 'count(%1$s[../@quiote_annotations_latest:marker = "%4$s"])';
			}
		}
		
		$retval = (int)$this->ownerDocument->getXpath()->evaluate(sprintf($query, $name, $singularName, $namespaceUri, $marker), $this);
		
		$this->removeAttributeNS(XmlConfigParser::NAMESPACE_QUIOTE_ANNOTATIONS_LATEST, 'quiote_annotations_latest:marker');
		
		return $retval;
	}
	
	/**
	 * Determine whether there is at least one instance of a child element with a
	 * given name.
	 * @param      string $name The name of the element.
	 * @param      string $namespaceUri The namespace URI. If null, the document default
	 *                    namespace will be used. If an empty string, no namespace
	 *                    will be used.
	 * @param      bool $pluralMagic Whether or not to apply automatic singular/plural
	 *                    handling that skips plural container elements.
	 * @return     bool True if one or more child elements with the given name
	 *                  exist, false otherwise.
	 * @since      1.0.0
	 */
	public function hasChildren($name, $namespaceUri = null, $pluralMagic = false): bool
	{
		return $this->countChildren($name, $namespaceUri, $pluralMagic) !== 0;
	}
	
	/**
	 * Retrieve all children with the given element name.
	 * @param      string $name The name of the element.
	 * @param      string $namespaceUri The namespace URI. If null, the document default
	 *                    namespace will be used. If an empty string, no namespace
	 *                    will be used.
	 * @param      bool $pluralMagic Whether or not to apply automatic singular/plural
	 *                    handling that skips plural container elements.
	 * @return     array<int, XmlConfigDomElement> A list of the child elements.
	 * @since      1.0.0
	 */
	public function getChildren($name, $namespaceUri = null, $pluralMagic = false): array
	{
		// if arg is null, then only check for elements from our default namespace
		// if namespace uri is null, use default ns. if empty string, use no ns
		$namespaceUri ??= $this->ownerDocument->getDefaultNamespaceUri();
		
		// init our vars
		$query = '';
		$singularName = null;
		
		// tag our element, because libxml will mess things up otherwise
		$marker = uniqid('', true);
		$this->setAttributeNS(XmlConfigParser::NAMESPACE_QUIOTE_ANNOTATIONS_LATEST, 'quiote_annotations_latest:marker', $marker);
		
		if($pluralMagic) {
			// we always assume that we either get plural names, or the singular of the singular is not different from the singular :)
			$singularName = $this->singularize($name);
			if($namespaceUri) {
				$query = 'child::*[local-name() = "%2$s" and namespace-uri() = "%3$s" and ../@quiote_annotations_latest:marker = "%4$s"] | child::*[local-name() = "%1$s" and namespace-uri() = "%3$s" and ../@quiote_annotations_latest:marker = "%4$s"]/*[local-name() = "%2$s" and namespace-uri() = "%3$s" and ../../@quiote_annotations_latest:marker = "%4$s"]';
			} else {
				$query = '%1$s[../@quiote_annotations_latest:marker = "%4$s"]/%2$s[../../@quiote_annotations_latest:marker = "%4$s"] | %2$s[../@quiote_annotations_latest:marker = "%4$s"]';
			}
		} else {
			if($namespaceUri) {
				$query = 'child::*[local-name() = "%1$s" and namespace-uri() = "%3$s" and ../@quiote_annotations_latest:marker = "%4$s"]';
			} else {
				$query = '%1$s[../@quiote_annotations_latest:marker = "%4$s"]';
			}
		}
		
		$result = $this->ownerDocument->query(sprintf($query, $name, $singularName, $namespaceUri, $marker), $this);

		$this->removeAttributeNS(XmlConfigParser::NAMESPACE_QUIOTE_ANNOTATIONS_LATEST, 'quiote_annotations_latest:marker');

		// registerNodeClass() guarantees element nodes are always XmlConfigDomElement.
		$retval = [];
		foreach ($result as $node) {
			if ($node instanceof self) {
				$retval[] = $node;
			}
		}

		return $retval;
	}
	
	/**
	 * Determine whether this element has a particular child element. This method
	 * succeeds only when there is exactly one child element with the given name.
	 * @param      string $name The name of the element.
	 * @param      string $namespaceUri The namespace URI. If null, the document default
	 *                    namespace will be used. If an empty string, no namespace
	 *                    will be used.
	 * @return     bool True if there is exactly one instance of an element with
	 *                  the given name; false otherwise.
	 * @since      1.0.0
	 */
	public function hasChild($name, $namespaceUri = null): bool
	{
		return $this->getChild($name, $namespaceUri) !== null;
	}
	
	/**
	 * Return a single child element with a given name.
	 * Only returns anything if there is exactly one child of this name.
	 * @param      string $name The name of the element.
	 * @param      string $namespaceUri The namespace URI. If null, the document default
	 *                    namespace will be used. If an empty string, no namespace
	 *                    will be used.
	 * @return     ?XmlConfigDomElement The child element, or null if none exists.
	 * @since      1.0.0
	 */
	public function getChild($name, $namespaceUri = null): null|XmlConfigDomElement
	{
		// if arg is null, then only check for elements from our default namespace
		// if namespace uri is null, use default ns. if empty string, use no ns
		$namespaceUri ??= $this->ownerDocument->getDefaultNamespaceUri();
		
		// tag our element, because libxml will mess things up otherwise
		$marker = uniqid('', true);
		$this->setAttributeNS(XmlConfigParser::NAMESPACE_QUIOTE_ANNOTATIONS_LATEST, 'quiote_annotations_latest:marker', $marker);
		
		if($namespaceUri) {
			$query = 'self::node()[count(child::*[local-name() = "%1$s" and namespace-uri() = "%2$s" and ../@quiote_annotations_latest:marker = "%3$s"]) = 1]/*[local-name() = "%1$s" and namespace-uri() = "%2$s" and ../@quiote_annotations_latest:marker = "%3$s"]';
		} else {
			$query = 'self::node()[count(child::%1$s[../@quiote_annotations_latest:marker = "%3$s"]) = 1]/%1$s[../@quiote_annotations_latest:marker = "%3$s"]';
		}
		
		$retval = $this->ownerDocument->query(sprintf($query, $name, $namespaceUri, $marker), $this)->item(0);

		$this->removeAttributeNS(XmlConfigParser::NAMESPACE_QUIOTE_ANNOTATIONS_LATEST, 'quiote_annotations_latest:marker');

		// The query above only ever selects element nodes, and registerNodeClass()
		// guarantees those are always XmlConfigDomElement, never a vanilla DOMNode.
		return $retval instanceof self ? $retval : null;
	}
	
	/**
	 * Retrieve an attribute value.
	 * Unlike DOMElement::getAttribute(), this method accepts an optional default
	 * return value.
	 * @param      string $name An attribute name.
	 * @param      ?string $default A default attribute value.
	 * @return     ?string An attribute value, if the attribute exists, otherwise
	 *                   null or the given default.
	 * @see        DOMElement::getAttribute()
	 * @since      1.0.0
	 */
	#[ReturnTypeWillChange]
	public function getAttribute($name, $default = null): string|null
	{
		$retval = parent::getAttribute($name);
		
		// getAttribute returns '' when the attribute doesn't exist, but any
		// null-ish value is probably unacceptable anyway
		if($retval == null) {
			$retval = $default;
		}
		
		return $retval;
	}
	
	/**
	 * Retrieve a namespaced attribute value.
	 * Unlike DOMElement::getAttributeNS(), this method accepts an optional
	 * default return value.
	 * @param      string $namespaceUri A namespace URI.
	 * @param      string $localName An attribute name.
	 * @param      ?string $default A default attribute value.
	 * @return     ?string An attribute value, if the attribute exists, otherwise
	 *                   null or the given default.
	 * @see        DOMElement::getAttributeNS()
	 * @since      1.0.0
	 */
	#[ReturnTypeWillChange]
	public function getAttributeNS($namespaceUri, $localName, $default = null): string|null
	{
		$retval = parent::getAttributeNS($namespaceUri, $localName);

		if($retval == null) {
			$retval = $default;
		}
		
		return $retval;
	}
	
	/**
	 * Retrieve all attributes of the element that are in no namespace.
	 * @return     array<string,?string> An associative array of attribute names and values.
	 * @since      1.0.0
	 */
	public function getAttributes(): array
	{
		return $this->getAttributesNS('');
	}
	
	/**
	 * Retrieve all attributes of the element that are in the given namespace.
	 * @param      string $namespaceUri The namespace URI.
	 * @return     array<string,?string> An associative array of attribute names and values.
	 * @since      1.0.0
	 */
	public function getAttributesNS($namespaceUri): array
	{
		$retval = [];

		foreach($this->ownerDocument->query(sprintf('@*[namespace-uri() = "%s"]', $namespaceUri), $this) as $attribute) {
			// The query above only ever selects attribute nodes, so localName
			// is always present; guard defensively anyway since DOMNode::$localName
			// is nullable in general.
			if($attribute->localName === null) {
				continue;
			}
			$retval[$attribute->localName] = $attribute->nodeValue;
		}

		return $retval;
	}
	
	/**
	 * Check whether or not the element has Quiote parameters as children.
	 * @return     bool True, if there are parameters, false otherwise.
	 * @since      1.0.0
	 */
	public function hasQuioteParameters(): bool
	{
		if($this->ownerDocument->isQuioteConfiguration()) {
			return $this->has('parameters', XmlConfigParser::NAMESPACE_QUIOTE_ENVELOPE_LATEST);
		}
		
		return false;
	}
	
	/**
	 * Retrieve all of the Quiote parameter elements associated with this
	 * element.
	 * @param      array<int|string,mixed> $existing An array of existing parameters.
	 * @return     array<int|string,mixed> The complete array of parameters.
	 * @since      1.0.0
	 */
	public function getQuioteParameters(array $existing = []): array
	{
		$result = $existing;
		$offset = 0;
		
		if($this->ownerDocument->isQuioteConfiguration()) {
			$elements = $this->get('parameters', XmlConfigParser::NAMESPACE_QUIOTE_ENVELOPE_LATEST);
			
			foreach($elements as $element) {
				// See the class docblock: registerNodeClass() guarantees every node here
				// is a XmlConfigDomElement, never a vanilla DOMNode.
				/** @var XmlConfigDomElement $element */
				$key = null;
				if(!$element->hasAttribute('name')) {
					$result[$key = $offset++] = null;
				} else {
					// getAttribute() treats an existing-but-empty "name" attribute the
					// same as a missing one and returns null; fall back to '' so this
					// still becomes a legal (and, for PHP arrays, equivalent) key,
					// rather than crashing.
					$key = $element->getAttribute('name') ?? '';
				}
				
				if($element->hasQuioteParameters()) {
					$result[$key] = isset($result[$key]) && is_array($result[$key]) ? $result[$key] : [];
					$result[$key] = $element->getQuioteParameters($result[$key]);
				} else {
					$result[$key] = $element->getLiteralValue();
				}
			}
		}
		
		return $result;
	}
}

?>