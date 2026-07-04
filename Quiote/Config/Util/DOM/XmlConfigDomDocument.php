<?php
namespace Quiote\Config\Util\DOM;

use Quiote\Config\XmlConfigParser;

/**
 * Extended DOMDocument class with several convenience enhancements.
 * @since      1.0.0
 * @version    1.0.0
 */
class XmlConfigDomDocument extends \DOMDocument
{
	/**
	 * @var        string Default namespace used by several convenience methods in
	 *                    other node classes to access/retrieve elements.
	 */
	protected $defaultNamespaceUri = '';
	
	/**
	 * @var        string XPath prefix of the default namespace defined above.
	 */
	protected $defaultNamespacePrefix = '';
	
	/**
	 * @var        \DOMXPath A DOMXPath instance for this document.
	 */
	protected $xpath = null;
	
	/**
	 * @var        array A map of DOM classes and extended Quiote implementations.
	 */
	protected $nodeClassMap = [
		'DOMAttr'                  => \Quiote\Config\Util\DOM\XmlConfigDomAttr::class,
		'DOMCharacterData'         => \Quiote\Config\Util\DOM\XmlConfigDomCharacterData::class,
		'DOMComment'               => \Quiote\Config\Util\DOM\XmlConfigDomComment::class,
		// yes, even DOMDocument, so we don't get back a vanilla DOMDocument when doing $doc->documentElement etc
		'DOMDocument'              => \Quiote\Config\Util\DOM\XmlConfigDomDocument::class,
		'DOMDocumentFragment'      => \Quiote\Config\Util\DOM\XmlConfigDomDocumentFragment::class,
		'DOMDocumentType'          => \Quiote\Config\Util\DOM\XmlConfigDomDocumentType::class,
		'DOMElement'               => \Quiote\Config\Util\DOM\XmlConfigDomElement::class,
		'DOMEntity'                => \Quiote\Config\Util\DOM\XmlConfigDomEntity::class,
		'DOMEntityReference'       => \Quiote\Config\Util\DOM\XmlConfigDomEntityReference::class,
		'DOMNode'                  => \Quiote\Config\Util\DOM\XmlConfigDomNode::class,
		// 'DOMNotation'              => 'Quiote\Config\Util\DOM\XmlConfigDomNotation',
		'DOMProcessingInstruction' => \Quiote\Config\Util\DOM\XmlConfigDomProcessingInstruction::class,
		'DOMText'                  => \Quiote\Config\Util\DOM\XmlConfigDomText::class,
	];
	
	/**
	 * The constructor.
	 * Will auto-register Quiote DOM node classes and create an XPath instance.
	 * @param      string $version The XML version.
	 * @param      string $encoding The XML encoding.
	 * @see        DOMDocument::__construct()
	 * @since      1.0.0
	 */
	public function __construct($version = "1.0", $encoding = "UTF-8")
	{
		parent::__construct($version, $encoding);
		
		foreach($this->nodeClassMap as $domClass => $quioteClass) {
			$this->registerNodeClass($domClass, $quioteClass);
		}
		
		$this->xpath = new \DOMXPath($this);
	}
	
	/**
	 * Load XML from a file.
	 * @param      string $filename The path to the XML document.
	 * @param      int $options Bitwise OR of the libxml option constants.
	 * @return     bool True of the operation is successful; false otherwise.
	 * @since      1.0.0
	 */
	public function load($filename, $options = 0) : bool
	{
		// Force-disable network access during parsing (external DTDs/entities,
		// XInclude). Defense-in-depth: config files are trusted, but this guarantees
		// no SSRF/remote fetch even if a flag like resolveExternals is ever enabled.
		// NB: we deliberately do NOT add LIBXML_NOENT (that would ENABLE dangerous
		// entity substitution / XXE).
		$options |= LIBXML_NONET;
		$luie = libxml_use_internal_errors(true);
		libxml_clear_errors();

		$result = parent::load($filename, $options);
		
		if(libxml_get_last_error() !== false) {
			$errors = [];
			foreach(libxml_get_errors() as $error) {
				$errors[] = sprintf('[%s #%d] Line %d: %s', $error->level == LIBXML_ERR_WARNING ? 'Warning' : ($error->level == LIBXML_ERR_ERROR ? 'Error' : 'Fatal'), $error->code, $error->line, $error->message);
			}
			libxml_clear_errors();
			libxml_use_internal_errors($luie);
			throw new \DOMException(
				sprintf(
					'Error%s occurred while parsing the document: ' . "\n\n%s",
					count($errors) > 1 ? 's' : '',
					implode("\n", $errors)
				)
			);
		}
		
		libxml_use_internal_errors($luie);
		
		$this->xpath = new \DOMXPath($this);
		
		if($this->isQuioteConfiguration()) {
			XmlConfigParser::registerQuioteNamespaces($this);
		}
		
		return $result;
	}
	
	/**
	 * Load XML from a string.
	 * @param      string $source The string containing the XML.
	 * @param      int $options Bitwise OR of the libxml option constants.
	 * @return     bool True of the operation is successful; false otherwise.
	 * @since      1.0.0
	 */
	public function loadXml($source, $options = 0) : bool
	{
		// See load(): force LIBXML_NONET, never LIBXML_NOENT.
		$options |= LIBXML_NONET;
		$luie = libxml_use_internal_errors(true);
		libxml_clear_errors();

		$result = parent::loadXML($source, $options);
		
		if(libxml_get_last_error() !== false) {
			$errors = [];
			foreach(libxml_get_errors() as $error) {
				$errors[] = sprintf('[%s #%d] Line %d: %s', $error->level == LIBXML_ERR_WARNING ? 'Warning' : ($error->level == LIBXML_ERR_ERROR ? 'Error' : 'Fatal'), $error->code, $error->line, $error->message);
			}
			libxml_clear_errors();
			libxml_use_internal_errors($luie);
			throw new \DOMException(
				sprintf(
					'Error%s occurred while parsing the document: ' . "\n\n%s",
					count($errors) > 1 ? 's' : '',
					implode("\n", $errors)
				)
			);
		}
		
		libxml_use_internal_errors($luie);
		
		$this->xpath = new \DOMXPath($this);
		
		if($this->isQuioteConfiguration()) {
			XmlConfigParser::registerQuioteNamespaces($this);
		}
		
		return $result;
	}
	
	/**
	 * Substitutes XIncludes in a DOMDocument object.
	 * @param      int $options Bitwise OR of the libxml option constants.
	 * @return     int The number of XIncludes in the document.
	 * @since      1.0.0
	 */
	public function xinclude($options = 0): false|int
	{
		// Block network-sourced XIncludes (xi:include href="http://...").
		$options |= LIBXML_NONET;
		$luie = libxml_use_internal_errors(true);
		libxml_clear_errors();

		$result = parent::xinclude($options);
		
		if(libxml_get_last_error() !== false) {
			$throw = false;
			$errors = [];
			foreach(libxml_get_errors() as $error) {
				if($error->level != LIBXML_ERR_WARNING) {
					$throw = true;
				}
				$errors[] = sprintf('[%s #%d] Line %d: %s', $error->level == LIBXML_ERR_WARNING ? 'Warning' : ($error->level == LIBXML_ERR_ERROR ? 'Error' : 'Fatal'), $error->code, $error->line, $error->message);
			}
			libxml_clear_errors();
			if($throw) {
				libxml_use_internal_errors($luie);
				throw new \DOMException(
					sprintf(
						'Error%s occurred while resolving XInclude directives: ' . "\n\n%s", 
						count($errors) > 1 ? 's' : '', 
						implode("\n", $errors)
					)
				);
			}
		}
		
		libxml_use_internal_errors($luie);
		
		return $result;
	}
	
	/**
	 * Import a node into the current document.
	 * @param      \DOMNode $node The node to import.
	 * @param      bool $deep Whether or not to recursively import the node's
	 *                     subtree.
	 * @return     mixed The copied node, or false if it cannot be copied.
	 * @since      1.0.0
	 */
	public function importNode(\DOMNode $node, $deep = false)
	{
		$luie = libxml_use_internal_errors(true);
		libxml_clear_errors();
		
		$result = parent::importNode($node, $deep);
		
		if(libxml_get_last_error() !== false) {
			$errors = [];
			foreach(libxml_get_errors() as $error) {
				$errors[] = sprintf('[%s #%d] Line %d: %s', $error->level == LIBXML_ERR_WARNING ? 'Warning' : ($error->level == LIBXML_ERR_ERROR ? 'Error' : 'Fatal'), $error->code, $error->line, $error->message);
			}
			libxml_clear_errors();
			libxml_use_internal_errors($luie);
			throw new \DOMException(
				sprintf(
					'Error%s occurred while importing a new node "%s": ' . "\n\n%s",
					count($errors) > 1 ? 's' : '', 
					$node->nodeName,
					implode("\n", $errors)
				)
			);
		}
		
		libxml_use_internal_errors($luie);
		
		return $result;
	}
	
	/**
	 * Validate a document based on a schema.
	 * @param      string $filename The path to the schema.
	 * @return     bool True if the validation is successful; false otherwise.
	 * @since      1.0.0
	 */
	public function schemaValidate($filename, $flags = 0): bool
	{
		$luie = libxml_use_internal_errors(true);
		libxml_clear_errors();
		
		// gotta do the @ to suppress PHP warnings when the schema cannot be loaded or is invalid
		if(!$result = @parent::schemaValidate($filename, $flags)) {
			$errors = [];
			foreach(libxml_get_errors() as $error) {
				$errors[] = sprintf('[%s #%d] Line %d: %s', $error->level == LIBXML_ERR_WARNING ? 'Warning' : ($error->level == LIBXML_ERR_ERROR ? 'Error' : 'Fatal'), $error->code, $error->line, $error->message);
			}
			libxml_clear_errors();
			libxml_use_internal_errors($luie);
			throw new \DOMException(
				sprintf(
					'XML Schema validation with "%s" failed due to the following error%s: ' . "\n\n%s", 
					$filename, 
					count($errors) > 1 ? 's' : '', 
					implode("\n", $errors)
				)
			);
		}
		
		libxml_use_internal_errors($luie);
		
		return $result;
	}
	
	/**
	 * Validate a document based on a schema.
	 * @param      string $source A string containing the schema.
	 * @return     bool True if the validation is successful; false otherwise.
	 * @since      1.0.0
	 */
	public function schemaValidateSource($source, $flags = 0): bool
	{
		$luie = libxml_use_internal_errors(true);
		libxml_clear_errors();
		
		// gotta do the @ to suppress PHP warnings when the schema cannot be loaded or is invalid
		if(!$result = @parent::schemaValidateSource($source, $flags)) {
			$errors = [];
			foreach(libxml_get_errors() as $error) {
				$errors[] = sprintf('[%s #%d] Line %d: %s', $error->level == LIBXML_ERR_WARNING ? 'Warning' : ($error->level == LIBXML_ERR_ERROR ? 'Error' : 'Fatal'), $error->code, $error->line, $error->message);
			}
			libxml_clear_errors();
			libxml_use_internal_errors($luie);
			throw new \DOMException(
				sprintf(
					'XML Schema validation failed due to the following error%s: ' . "\n\n%s", 
					count($errors) > 1 ? 's' : '', 
					implode("\n", $errors)
				)
			);
		}
		
		libxml_use_internal_errors($luie);
		
		return $result;
	}
	
	/**
	 * Perform RELAX NG validation on the document.
	 * @param      string $filename The path to the schema.
	 * @return     bool True if the validation is successful; false otherwise.
	 * @since      1.0.0
	 */
	public function relaxNGValidate($filename): bool
	{
		$luie = libxml_use_internal_errors(true);
		libxml_clear_errors();
		
		// gotta do the @ to suppress PHP warnings when the schema cannot be loaded or is invalid
		if(!$result = @parent::relaxNGValidate($filename)) {
			$errors = [];
			foreach(libxml_get_errors() as $error) {
				$errors[] = sprintf('[%s #%d] Line %d: %s', $error->level == LIBXML_ERR_WARNING ? 'Warning' : ($error->level == LIBXML_ERR_ERROR ? 'Error' : 'Fatal'), $error->code, $error->line, $error->message);
			}
			libxml_clear_errors();
			libxml_use_internal_errors($luie);
			throw new \DOMException(
				sprintf(
					'RELAX NG validation with "%s" failed due to the following error%s: ' . "\n\n%s",
					$filename,
					count($errors) > 1 ? 's' : '', 
					implode("\n", $errors)
				)
			);
		}
		
		libxml_use_internal_errors($luie);
		
		return $result;
	}
	
	/**
	 * Retrieve the DOMXPath instance that is associated with this document.
	 * @return     \DOMXPath The DOMXPath instance.
	 * @since      1.0.0
	 */
	public function getXpath()
	{
		return $this->xpath;
	}
	
	/**
	 * Set a default namespace that should be used when accessing elements via
	 * convenience methods (such as magic get overload for children), and bind it
	 * to the given prefix for use in XPath expressions.
	 * @param      string $namespaceUri A namespace URI
	 * @param      string $prefix An optional prefix, defaulting to "_default"
	 * @since      1.0.0
	 */
	public function setDefaultNamespace($namespaceUri, $prefix = '_default')
	{
		$this->defaultNamespaceUri = $namespaceUri;
		$this->defaultNamespacePrefix = $prefix;
		
		$this->xpath->registerNamespace($prefix, $namespaceUri);
	}
	
	/**
	 * Retrieve the default namespace URI that will be used by node classes, if
	 * set, to conveniently retrieve child elements etc in some methods.
	 * @return     string A namespace URI.
	 * @since      1.0.0
	 */
	public function getDefaultNamespaceUri()
	{
		return $this->defaultNamespaceUri;
	}
	
	/**
	 * Retrieve the default namespace prefix that will be used by node classes, if
	 * set, to conveniently retrieve child elements etc via XPath. 
	 * @return     string A namespace prefix.
	 * @since      1.0.0
	 */
	public function getDefaultNamespacePrefix()
	{
		return $this->defaultNamespacePrefix;
	}
	
	/**
	 * Check whether or not this is a standard Quiote configuration file, i.e. with
	 * a <configurations> and <configuration> envelope.
	 * @return     bool true, if it is an Quiote config structure, false otherwise.
	 * @since      1.0.0
	 */
	public function isQuioteConfiguration()
	{
		return XmlConfigParser::isQuioteConfigurationDocument($this);
	}
	
	/**
	 * Retrieve the namespace of the Quiote envelope.
	 * @return     ?string A namespace URI, or null if it's not an Quiote config.
	 * @since      1.0.0
	 */
	public function getQuioteEnvelopeNamespace()
	{
		if($this->isQuioteConfiguration()) {
			return $this->documentElement->namespaceURI;
		}

		return null;
	}
	
	/**
	 * Method to retrieve a list of Quiote <configuration> elements regardless of
	 * their namespace.
	 * @return     array A list of XmlConfigDomElement elements.
	 * @since      1.0.0
	 */
	public function getConfigurationElements()
	{
		$retval = [];
		
		if($this->isQuioteConfiguration()) {
			$quioteNs = $this->getQuioteEnvelopeNamespace();
			
			foreach($this->documentElement->childNodes as $node) {
				if($node->nodeType == XML_ELEMENT_NODE && $node->localName == 'configuration' && $node->namespaceURI == $quioteNs) {
					$retval[] = $node;
				}
			}
		}
		
		return $retval;
	}
	
	/**
	 * Method to retrieve the Quiote <sandbox> element regardless of the namespace.
	 * @return     ?XmlConfigDomElement The <sandbox> element, or null.
	 * @since      1.0.0
	 */
	public function getSandbox()
	{
		if($this->isQuioteConfiguration()) {
			$quioteNs = $this->getQuioteEnvelopeNamespace();
			
			foreach($this->documentElement->childNodes as $node) {
				if($node->nodeType == XML_ELEMENT_NODE && $node->localName == 'sandbox' && $node->namespaceURI == $quioteNs) {
					// registerNodeClass() guarantees element nodes are always
					// XmlConfigDomElement, never a vanilla DOMNode.
					/** @var XmlConfigDomElement $node */
					return $node;
				}
			}
		}

		return null;
	}
}

?>