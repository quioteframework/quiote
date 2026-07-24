<?php
namespace Quiote\Config;

use Quiote\Config\Format\Xml\ElementPositionIndex;
use Quiote\Config\Util\DOM\XmlConfigDomDocument;
use Quiote\Config\Util\DOM\XmlConfigDomElement;
use Quiote\Exception\ParseException;
use Quiote\Exception\UnreadableException;
use Quiote\Util\SchematronProcessor;
use Quiote\Util\Toolkit;
use Quiote\Util\QuioteXsltProcessor;

/**
 * XmlConfigParser handles both Quiote and foreign XML configuration files,
 * deals with XIncludes, XSL transformations and validation as well as filtering
 * and ordering of configuration blocks and parent file resolution and parsing.
 * @since      1.0.0
 * @version    1.0.0
 */
class XmlConfigParser
{
	const NAMESPACE_QUIOTE_ENVELOPE_1_1 = 'http://quiote.dev/quiote/config/global/envelope/1.1';

	const NAMESPACE_QUIOTE_ENVELOPE_LATEST = self::NAMESPACE_QUIOTE_ENVELOPE_1_1;

	/**
	 * @var        array<int,string> Envelope namespace URIs that Quiote used
	 *                   prior to 1.1 and no longer supports. Kept only so a
	 *                   config file still written against one of them can be
	 *                   rejected with a clear error instead of silently being
	 *                   treated as a foreign (non-Quiote) XML document.
	 */
	private const LEGACY_ENVELOPE_NAMESPACES = [
		'http://quiote.org/quiote/1.0/config',
		'http://quiote.dev/quiote/config/global/envelope/1.0',
	];
	
	const NAMESPACE_QUIOTE_ANNOTATIONS_1_0 = 'http://quiote.dev/quiote/config/global/annotations/1.0';
	
	const NAMESPACE_QUIOTE_ANNOTATIONS_LATEST = self::NAMESPACE_QUIOTE_ANNOTATIONS_1_0;
	
	const VALIDATION_TYPE_XMLSCHEMA = 'xml_schema';
	
	const VALIDATION_TYPE_RELAXNG = 'relax_ng';
	
	const VALIDATION_TYPE_SCHEMATRON = 'schematron';
	
	const NAMESPACE_SCHEMATRON_ISO = 'http://purl.oclc.org/dsdl/schematron';
	
	const NAMESPACE_SVRL_ISO = 'http://purl.oclc.org/dsdl/svrl';
	
	const NAMESPACE_XML_1998 = 'http://www.w3.org/XML/1998/namespace'; 
	
	const NAMESPACE_XMLNS_2000 = 'http://www.w3.org/2000/xmlns/';
	
	const NAMESPACE_XSL_1999 = 'http://www.w3.org/1999/XSL/Transform';
	
	const NAMESPACE_XINCLUDE_2001 = 'http://www.w3.org/2001/XInclude';
	
	const STAGE_SINGLE = 'single';
	
	const STAGE_COMPILATION = 'compilation';
	
	const STEP_TRANSFORMATIONS_BEFORE = 'transformations_before';
	
	const STEP_TRANSFORMATIONS_AFTER = 'transformations_after';
	
	/**
	 * @var        array<string,string> A list of XML namespaces for Quiote configuration files as
	 *                   keys and their associated XPath namespace prefix (value).
	 */
	public static $quioteEnvelopeNamespaces = [
		self::NAMESPACE_QUIOTE_ENVELOPE_1_1 => 'quiote_envelope_1_1',
	];

	/**
	 * @var        array<string,string> A list of all XML namespaces that are used internally by
	 *                   the configuration parser.
	 */
	public static $quioteNamespaces = [
		self::NAMESPACE_QUIOTE_ENVELOPE_1_1 => 'quiote_envelope_1_1',
		self::NAMESPACE_QUIOTE_ANNOTATIONS_1_0 => 'quiote_annotations_1_0',
	];
	
	/**
	 * @var        string Path to the config file we're parsing in this instance.
	 */
	protected $path = '';
	
	/**
	 * @var        ?string The name of the current environment, or null if none is configured.
	 */
	protected $environment = '';
	
	/**
	 * @var        XmlConfigDomDocument The document we're parsing here.
	 */
	protected final XmlConfigDomDocument $doc;
	
	/**
	 * Test if the given document looks like an Quiote config file.
	 * @param      XmlConfigDomDocument $doc The document to test.
	 * @return     bool True, if it is an Quiote config document, false otherwise.
	 * @since      1.0.0
	 */
	public static function isQuioteConfigurationDocument(XmlConfigDomDocument $doc)
	{
		$namespaceUri = $doc->documentElement?->namespaceURI;
		return $doc->documentElement !== null && $doc->documentElement->localName == 'configurations' && $namespaceUri !== null && self::isQuioteEnvelopeNamespace($namespaceUri);
	}
	
	/**
	 * Check if the given namespace URI is a valid Quiote envelope namespace.
	 * @param      string $namespaceUri The namespace URI.
	 * @return     bool True, if the given URI is a valid namespace URI, or false.
	 * @since      1.0.0
	 */
	public static function isQuioteEnvelopeNamespace($namespaceUri)
	{
		return isset(self::$quioteEnvelopeNamespaces[$namespaceUri]);
	}

	/**
	 * Check if the given namespace URI is a Quiote envelope namespace that
	 * used to be supported (pre-1.1) but has since been dropped.
	 * @param      string $namespaceUri The namespace URI.
	 * @return     bool True, if the given URI is a legacy envelope namespace, false otherwise.
	 * @since      1.0.0
	 */
	public static function isLegacyEnvelopeNamespace($namespaceUri)
	{
		return in_array($namespaceUri, self::LEGACY_ENVELOPE_NAMESPACES, true);
	}
	
	/**
	 * Check if a given namespace URI is a valid Quiote namespace.
	 * @param      string $namespaceUri The namespace URI.
	 * @return     bool True if the given URI is a valid namespace URI,
	 *                  false otherwise.
	 * @since      1.0.0
	 */
	public static function isQuioteNamespace($namespaceUri)
	{
		return isset(self::$quioteNamespaces[$namespaceUri]);
	}
	
	/**
	 * Retrieves an XPath namespace prefix based on a given namespace URI.
	 * @param      string $namespaceUri The namespace URI.
	 * @return     ?string The prefix for the namespace URI, or null if none
	 *                    exists.
	 * @since      1.0.0
	 */
	public static function getQuioteNamespacePrefix($namespaceUri)
	{
		if(self::isQuioteNamespace($namespaceUri)) {
			return self::$quioteNamespaces[$namespaceUri];
		}
		return null;
	}
	
	/**
	 * Register Quiote namespace prefixes in a given document.
	 * @param      XmlConfigDomDocument $document The document.
	 * @return     void
	 * @since      1.0.0
	 */
	public static function registerQuioteNamespaces(XmlConfigDomDocument $document)
	{
		$xpath = $document->getXpath();
		
		foreach(self::$quioteNamespaces as $namespaceUri => $prefix) {
			$xpath->registerNamespace($prefix, $namespaceUri);
		}
		
		/* Register the latest namespaces. */
		$xpath->registerNamespace('quiote_envelope_latest', self::NAMESPACE_QUIOTE_ENVELOPE_LATEST);
		$xpath->registerNamespace('quiote_annotations_latest', self::NAMESPACE_QUIOTE_ANNOTATIONS_LATEST);
	}
	                                                 
	/**
	 * @param      string  $path An absolute filesystem path to a configuration file.
	 * @param      ?string $environment The environment name, or null to resolve it from core.environment (which may itself be unset -- see the constructor).
	 * @param      ?string $context The optional context name.
	 * @param      array<string,array<int,string>> $transformationInfo An associative array of transformation information.
	 * @param      array<string,mixed> $validationInfo An associative array of validation information.
	 * @param      ?ElementPositionIndex $positions When given, populated with a
	 *                    {file, line} entry for every merged <configuration>
	 *                    element (and its descendants) whose pre-merge source
	 *                    node still had a real line number -- i.e. it survived
	 *                    to the merge step without being cloned/transformed
	 *                    away first. Left untouched (and this is a no-op) when
	 *                    null, which is the default for every existing caller.
	 * @return     XmlConfigDomDocument A properly merged DOMDocument.
	 * @since      1.0.0
	 */
	public static function run(string $path, ?string $environment, ?string $context = null, array $transformationInfo = [], array $validationInfo = [], ?ElementPositionIndex $positions = null)
	{
		$isQuioteConfigFormat = true;
		// A transformed node can retain a stale, misleading getLineNo() rather
		// than reliably reporting 0 (some XSL constructs -- e.g. xsl:copy-of --
		// carry a leftover line annotation through libxml's node-copy machinery
		// that no longer corresponds to this node's real position). Checking
		// getLineNo() > 0 alone is not enough to tell a trustworthy position
		// from a stale one, so positions are only ever captured when no
		// transformation stage will actually run for this parse at all --
		// $transformationInfo is the same for every file in a parent chain, so
		// this is computed once, not per file.
		$singleStageTransformations = self::stringList($transformationInfo[self::STAGE_SINGLE] ?? null);
		$transformsWillRun = $singleStageTransformations !== [] && !Config::getBool('core.skip_config_transformations', false);
		// build an array of documents (this one, and the parents)
		$docs = [];
		$previousPaths = [];
		$nextPath = $path;
		while($nextPath !== null) {
			// run the single stage parser
			$parser = new XmlConfigParser($nextPath, $environment, $context);
			$doc = $parser->execute($singleStageTransformations, self::arrayOrEmpty($validationInfo[self::STAGE_SINGLE] ?? null));
			
			// put the new document in the list
			$docs[] = $doc;

			// make sure it (still) is a <configurations> file with the proper Quiote namespace
			if($isQuioteConfigFormat) {
				$isQuioteConfigFormat = self::isQuioteConfigurationDocument($doc);
			}

			// is it an Quiote <configurations> element? does it have a parent attribute? yes? good. parse that next
			// TODO: support future namespaces
			$docRootElement = self::requireDocumentElement($doc);
			if($isQuioteConfigFormat && $docRootElement->hasAttribute('parent')) {
				$theNextPath = self::scalarToString(Toolkit::literalize($docRootElement->getAttribute('parent')));
				
				// no infinite loop plz, kthx
				if($nextPath === $theNextPath) {
					throw new ParseException(sprintf("Quiote detected an infinite loop while processing parent configuration files of \n%s\n\nFile\n%s\nincludes itself as a parent.", $path, $theNextPath));
				} elseif(isset($previousPaths[$theNextPath])) {
					throw new ParseException(sprintf("Quiote detected an infinite loop while processing parent configuration files of \n%s\n\nFile\n%s\nhas previously been included by\n%s", $path, $theNextPath, $previousPaths[$theNextPath]));
				} else {
					$previousPaths[$theNextPath] = $nextPath;
					$nextPath = $theNextPath;
				}
			} else {
				$nextPath = null;
			}
		}
		
		// TODO: use our own classes here that extend DOM*
		$retval = new XmlConfigDomDocument();
		foreach(self::$quioteEnvelopeNamespaces as $envelopeNamespaceUri => $envelopeNamespacePrefix) {
			$retval->getXpath()->registerNamespace($envelopeNamespacePrefix, $envelopeNamespaceUri);
		}
		
		if($isQuioteConfigFormat) {
			// if it is an Quiote config, we'll create a new document with all files' <configuration> blocks inside
			$retvalRootElement = new XmlConfigDomElement('configurations', null, self::NAMESPACE_QUIOTE_ENVELOPE_LATEST);
			$retval->appendChild($retvalRootElement);

			// reverse the array - we want the parents first!
			$docs = array_reverse($docs);

			$configurationElements = [];

			// TODO: I bet this leaks memory due to the nodes being taken out of the docs. beware circular refs!
			foreach($docs as $doc) {
				$docRootElement = self::requireDocumentElement($doc);
				// iterate over all nodes (attributes, <sandbox>, <configuration> etc) inside the document element and append them to the <configurations> element in our final document
				foreach($docRootElement->childNodes as $node) {
					if($node->nodeType == XML_ELEMENT_NODE && $node->localName == 'configuration' && $node->namespaceURI !== null && self::isQuioteEnvelopeNamespace($node->namespaceURI)) {
						// it's a <configuration> element - put that on a stack for processing
						$configurationElements[] = $node;
					} else {
						// import the node, recursively, and store the imported node
						$importedNode = self::requireImportedNode($retval->importNode($node, true));
						// now append it to the <configurations> element
						$retvalRootElement->appendChild($importedNode);
					}
				}
				// if it's a <configurations> element, then we need to copy the attributes from there
				if($doc->isQuioteConfiguration()) {
					$namespaces = $doc->query('namespace::*');
					foreach($namespaces as $namespace) {
						if($namespace->localName !== 'xml' && $namespace->localName != 'xmlns' && $namespace->namespaceURI !== null) {
							$retvalRootElement->setAttributeNS(self::NAMESPACE_XMLNS_2000, 'xmlns:' . $namespace->localName, $namespace->namespaceURI);
						}
					}
					foreach($docRootElement->attributes as $attribute) {
						// but not the "parent" attributes...
						if($attribute->namespaceURI === null && $attribute->localName === 'parent') {
							continue;
						}
						$importedAttribute = $retval->importNode($attribute, true);
						if (!$importedAttribute instanceof \DOMAttr) {
							throw new ParseException(sprintf('Configuration file "%s" has a "%s" attribute that could not be imported.', $path, $attribute->name));
						}
						$retvalRootElement->setAttributeNode($importedAttribute);
					}
				}
			}
			
			// generic <configuration> first, then those with an environment attribute, then those with context, then those with both
			$configurationOrder = [
				'count(self::node()[@quiote_annotations_latest:matched and not(@environment) and not(@context)])',
				'count(self::node()[@quiote_annotations_latest:matched and @environment and not(@context)])',
				'count(self::node()[@quiote_annotations_latest:matched and not(@environment) and @context])',
				'count(self::node()[@quiote_annotations_latest:matched and @environment and @context])',
			];
			
			// now we sort the nodes according to the rules
			foreach($configurationOrder as $xpath) {
				// append all matching nodes from the order array...
				foreach($configurationElements as &$element) {
					// registerNodeClass() guarantees every node here is a
					// XmlConfigDomElement, never a vanilla DOMNode.
					/** @var XmlConfigDomElement $element */
					// ... if the xpath matches, that is!
					if($element->ownerDocument->getXpath()->evaluate($xpath, $element)) {
						// it did, so import the node and append it to the result doc
						$importedNode = self::requireImportedNode($retval->importNode($element, true));
						$retvalRootElement->appendChild($importedNode);
						if ($positions !== null && !$transformsWillRun) {
							self::correlatePosition($element, $importedNode, $positions);
						}
					}
				}
			}

			// run the compilation stage parser
			$retval = self::executeCompilation($retval, $environment, $context, self::stringList($transformationInfo[self::STAGE_COMPILATION] ?? null), self::arrayOrEmpty($validationInfo[self::STAGE_COMPILATION] ?? null));
		} else {
			// it's not an quiote config file. just pass it through then
			$retval->appendChild(self::requireImportedNode($retval->importNode(self::requireDocumentElement($doc), true)));
		}
		
		// cleanup attempt
		unset($docs);
		
		// set the pseudo-document URI
		$retval->documentURI = $path;

		return $retval;
	}
	
	/**
	 * Builds a proper regular expression from the input pattern to test against
	 * the given subject. This is for "environment" and "context" attributes of
	 * configuration blocks in the files.
	 * @param      mixed $pattern A regular expression chunk without delimiters/anchors.
	 * @param      mixed $subject The subject to test against the pattern.
	 * @return     bool Whether or not the subject matched the pattern.
	 * @since      1.0.0
	 */
	public static function testPattern($pattern, $subject)
	{
		// four backslashes mean one literal backslash
		$pattern = preg_replace('/\\\\+#/', '\\#', self::scalarToString($pattern)) ?? '';
		return (preg_match('#^(' . implode('|', array_map(trim(...), explode(' ', $pattern))) . ')$#', self::scalarToString($subject)) > 0);
	}
	
	/**
     * The constructor.
     * Will make a DOMDocument instance using the given path.
     * @param      string $path The path to the configuration file.
     * @param      ?string $environment The optional name of the current environment.
     * @param      ?string $context The optional name of the current context.
     * @since      1.0.0
     */
    public function __construct($path, $environment = null, /**
     * @var        ?string The name of the current context.
     */
    protected ?string $context = null)
	{
		// store environment...
		if($environment === null) {
			$environment = Config::getNullableString('core.environment');
		}
		$this->environment = $environment;
		
		if(!is_readable($path)) {
			$error = 'Configuration file "' . $path . '" does not exist or is unreadable';
			throw new UnreadableException($error);
		}
		
		// store path to the config file
		$this->path = $path;
		
		// XmlConfigDomDocument has convenience methods!
		try {
			$this->doc = new XmlConfigDomDocument();
			$this->doc->substituteEntities = true;
			$loaded = $this->doc->load($path);
		} catch(\DOMException $dome) {
			throw new ParseException(sprintf('Configuration file "%s" could not be parsed: %s', $path, $dome->getMessage()), 0, $dome);
		}

		// load() can also fail by returning false instead of throwing (e.g. with
		// custom libxml error handling in place); previously this went
		// unnoticed here and surfaced later as a confusing null root element.
		if ($loaded === false || $this->doc->documentElement === null) {
			throw new ParseException(sprintf('Configuration file "%s" could not be parsed: the document is empty or malformed.', $path));
		}
	}
	
	/**
	 * Destructor to do the cleaning up.
	 * @since      1.0.0
	 */
	public function __destruct()
	{
		unset($this->doc);
	}
	
	/**
	 * @param      array<int,string> $transformationInfo An array of XSL paths for transformation.
	 * @param      array<string,mixed> $validationInfo An associative array of validation information.
	 * @return     XmlConfigDomDocument Our DOMDocument.
	 * @since      1.0.0
	 */
	public function execute(array $transformationInfo = [], array $validationInfo = [])
	{
		// a document written against a namespace Quiote used to support (pre-1.1) is not a
		// "foreign" document to silently pass through -- it's a Quiote config file that needs
		// migrating by hand, so fail loudly instead of mis-parsing it
		$rootNamespaceUri = $this->doc->documentElement?->namespaceURI;
		if($rootNamespaceUri !== null && self::isLegacyEnvelopeNamespace($rootNamespaceUri)) {
			throw new ParseException(sprintf(
				'Configuration file "%s" uses the unsupported legacy Quiote envelope namespace "%s". Update it to use the current envelope namespace "%s".',
				$this->path,
				$rootNamespaceUri,
				self::NAMESPACE_QUIOTE_ENVELOPE_LATEST
			));
		}

		// resolve xincludes
		self::xinclude($this->doc);

		// validate XMLSchema-instance declarations
		self::validateXsi($this->doc);
		
		// validate pre-transformation
		self::validate($this->doc, $this->environment, $this->context, self::arrayOrEmpty($validationInfo[XmlConfigParser::STEP_TRANSFORMATIONS_BEFORE] ?? null));
		
		// mark document for merging
		self::match($this->doc, $this->environment, $this->context);
		
		if(!Config::getBool('core.skip_config_transformations', false)) {
			// run inline transformations
			$this->doc = self::transformProcessingInstructions($this->doc, $this->environment, $this->context);
			
			// perform XSL transformations
			$this->doc = self::transform($this->doc, $this->environment, $this->context, $transformationInfo);
			
			// resolve xincludes again, since transformations may have introduced some
			self::xinclude($this->doc);
		}
		
		// validate post-transformation
		self::validate($this->doc, $this->environment, $this->context, self::arrayOrEmpty($validationInfo[XmlConfigParser::STEP_TRANSFORMATIONS_AFTER] ?? null));
		
		// clean up the document
		self::cleanup($this->doc);
		
		return $this->doc;
	}
	
	/**
	 * Executes the parser for a compilation document.
	 * @param      XmlConfigDomDocument $document The document to act upon.
	 * @param      ?string $environment The environment name, or null if none is configured.
	 * @param      ?string $context The context name, or null if none is configured.
	 * @param      array<int,string> $transformationInfo An array of XSL paths for transformation.
	 * @param      array<string,mixed> $validationInfo An associative array of validation information.
	 * @return     XmlConfigDomDocument The compiled document.
	 * @since      1.0.0
	 */
	public static function executeCompilation(XmlConfigDomDocument $document, ?string $environment, ?string $context, array $transformationInfo = [], array $validationInfo = [])
	{
		// resolve xincludes
		self::xinclude($document);

		// validate pre-transformation
		self::validate($document, $environment, $context, self::arrayOrEmpty($validationInfo[XmlConfigParser::STEP_TRANSFORMATIONS_BEFORE] ?? null));

		if(!Config::getBool('core.skip_config_transformations', false)) {
			// perform XSL transformations
			$document = self::transform($document, $environment, $context, $transformationInfo);

			// resolve xincludes again, since transformations may have introduced some
			self::xinclude($document);
		}

		// validate post-transformation
		self::validate($document, $environment, $context, self::arrayOrEmpty($validationInfo[XmlConfigParser::STEP_TRANSFORMATIONS_AFTER] ?? null));

		return $document;
	}
	
	/**
	 * Resolve xinclude directives on a given document.
	 * @param      XmlConfigDomDocument $document The document to act upon.
	 * @return     void
	 * @since      1.0.0
	 */
	public static function xinclude(XmlConfigDomDocument $document)
	{
		// expand directives, resolve globs and encode paths in XInclude href attributes
		$elements = $document->getElementsByTagNameNS(self::NAMESPACE_XINCLUDE_2001, 'include');
		$length = $elements->length;
		// we can't foreach() over the DOMNodeList as we're modifying it further below
		// see http://php.net/manual/en/class.domnodelist.php#83178
		for($i = 0; $i < $length; $i++) {
			$element = $elements->item($i);
			if($element === null) {
				continue;
			}
			if($element->hasAttribute('href')) {
				$attribute = $element->getAttributeNode('href');
				if($attribute === false) {
					// hasAttribute() just returned true, so this can't realistically happen
					continue;
				}
				$parts = explode('#', (string) $attribute->nodeValue, 2);
				$parts[0] = str_replace('\\', '/', Toolkit::expandDirectives($parts[0]) ?? '');
				$attribute->nodeValue = rawurlencode($parts[0]) . (isset($parts[1]) ? '#' . $parts[1] : '');
				if(str_contains($parts[0], '*') || str_contains($parts[0], '{')) {
					$glob = glob($parts[0], GLOB_BRACE);
					if($glob) {
						$glob = array_unique($glob); // it could be that someone used /path/to/{Foo,*}/burp.xml so Foo would come before all others, that's why we need to remove duplicates as the * would match Foo again
						$parentNode = $element->parentNode;
						if($parentNode === null) {
							throw new ParseException(sprintf('Configuration file "%s" has an <xi:include> element with a glob href but no parent node to expand it into.', $document->documentURI));
						}
						foreach($glob as $path) {
							// registerNodeClass() guarantees cloneNode() returns a
							// XmlConfigDomElement here, never a vanilla DOMNode.
							/** @var XmlConfigDomElement $new */
							$new = $element->cloneNode(true);
							$new->setAttribute('href', rawurlencode($path) . (isset($parts[1]) ? '#' . $parts[1] : ''));
							$parentNode->insertBefore($new, $element);
							++$i;
						}
						$parentNode->removeChild($element);
					}
				}
			}
		}
		
		// perform xincludes
		try {
			$document->xinclude();
		} catch(\DOMException $dome) {
			throw new ParseException(sprintf('Configuration file "%s" could not be parsed: %s', $document->documentURI, $dome->getMessage()), 0, $dome);
		}
		
		// remove all xml:base attributes inserted by XIncludes
		$nodes = $document->query('//@xml:base', $document);
		foreach($nodes as $node) {
			// The query selects attribute nodes only, and registerNodeClass()
			// guarantees they are always XmlConfigDomAttr, never a vanilla DOMNode.
			/** @var \Quiote\Config\Util\DOM\XmlConfigDomAttr $node */
			if($node->ownerElement !== null) {
				$node->ownerElement->removeAttributeNode($node);
			}
		}
	}
	
	/**
	 * Annotate the document with matched attributes against each configuration
	 * element that matches the given context and environment.
	 * @param      XmlConfigDomDocument $document The document to act upon.
	 * @param      ?string $environment The environment name, or null if none is configured.
	 * @param      ?string $context The context name, or null if none is configured.
	 * @return     void
	 * @since      1.0.0
	 */
	public static function match(XmlConfigDomDocument $document, $environment, ?string $context)
	{
		if($document->isQuioteConfiguration()) {
			// it's an quiote config, so we need to set "matched" flags on all <configuration> elements where "context" and "environment" attributes match the values below
			$testAttributes = [
				'context' => $context,
				'environment' => $environment,
			];
			
			foreach($document->getConfigurationElements() as $configuration) {
				// assume that the element counts as matched, in case it doesn't have "context" or "environment" attributes
				$matched = true;
				foreach($testAttributes as $attributeName => $attributeValue) {
					if($configuration->hasAttribute($attributeName)) {
						$matched = $matched && self::testPattern($configuration->getAttribute($attributeName, '') ?? '', $attributeValue);
					}
				}
				if($matched) {
					// if all was fine, we set the attribute. the element will then be kept in the merged result doc later
					$configuration->setAttributeNS(self::NAMESPACE_QUIOTE_ANNOTATIONS_LATEST, 'quiote_annotations_latest:matched', 'true');
				}
			}
		}
	}
	
	/**
	 * Transform the document using info from embedded processing instructions
	 * and given stylesheets.
	 * @param      XmlConfigDomDocument $document The document to act upon.
	 * @param      ?string $environment The environment name, or null if none is configured.
	 * @param      ?string $context The context name, or null if none is configured.
	 * @param      array<int,string> $transformationInfo An array of transformation information.
	 * @param      array<int,XmlConfigDomDocument> $transformations An array of XSL stylesheets in DOMDocument instances.
	 * @return     XmlConfigDomDocument The transformed document.
	 * @since      1.0.0
	 */
	public static function transform(XmlConfigDomDocument $document, $environment, ?string $context, array $transformationInfo = [], $transformations = [])
	{
		// loop over all the paths we found and load the files
		foreach($transformationInfo as $href) {
			try {
				$xsl = new XmlConfigDomDocument();
				$xsl->load($href);
			} catch(\DOMException $dome) {
				throw new ParseException(sprintf('Configuration file "%s" could not be parsed: Could not load XSL stylesheet "%s": %s', $document->documentURI, $href, $dome->getMessage()), 0, $dome);
			}
			
			// add them to the list of transformations to be done
			$transformations[] = $xsl;
		}
		
		// now let's perform the transformations
		foreach($transformations as $xsl) {
			// load the stylesheet document into an XSLTProcessor instance
			try {
				$proc = new QuioteXsltProcessor();
				// SECURITY: Calling registerPHPFunctions() with no argument exposes
				// EVERY PHP function to config stylesheets via php:function(...), which
				// is RCE-grade if a stylesheet is ever attacker-influenceable. We only
				// register an explicit ALLOW-LIST, configured via
				// core.config_xsl_allowed_php_functions (a function-name string or an
				// array of them). The default is null/empty => register NOTHING. If you
				// ship config stylesheets that legitimately need a PHP callback, set
				// this directive to the fully-qualified callable(s) they use, e.g.
				//   Config::set('core.config_xsl_allowed_php_functions',
				//       ['Quiote\\Validator\\DependencyManager::populateArgumentBaseKeyRefs']);
				$allowedPHPFunctions = \Quiote\Config\Config::getStringList('core.config_xsl_allowed_php_functions');
				if ($allowedPHPFunctions !== []) {
					$proc->registerPHPFunctions($allowedPHPFunctions);
				}
				$proc->importStylesheet($xsl);
			} catch(\Exception $e) {
				throw new ParseException(sprintf('Configuration file "%s" could not be parsed: Could not import XSL stylesheet "%s": %s', $document->documentURI, $xsl->documentURI, $e->getMessage()), 0, $e);
			}
			
			// set some info (config file path, context name, environment name) as params
			// first arg is the namespace URI, which PHP doesn't support. awesome. see http://bugs.php.net/bug.php?id=30622 for the sad details
			// we could use "quiote:context" etc, that does work even without such a prefix being declared in the stylesheet, but that would be completely non-XML-ish, confusing, and against the spec. so we use dots instead.
			// the string casts are required for hhvm ($context could be null for example and hhvm bails out on that)
			$proc->setParameter('', [
				'quiote.config_path' => (string)$document->documentURI,
				'quiote.environment' => (string)$environment,
				'quiote.context' => (string)$context,
			]);
			
			try {
				// transform the doc, requesting an XmlConfigDomDocument result so the
				// custom node classes (registerNodeClass()) are preserved post-transform
				$newdoc = $proc->transformToDoc($document, XmlConfigDomDocument::class);
			} catch(\Exception $e) {
				throw new ParseException(sprintf('Configuration file "%s" could not be parsed: Could not transform the document using the XSL stylesheet "%s": %s', $document->documentURI, $xsl->documentURI, $e->getMessage()), 0, $e);
			}
			
			// no errors and we got a document back? excellent. this will be our new baby from now. time to kill the old one
			
			// get the old document URI
			$documentUri = $document->documentURI;

			// and assign the new document to the old one
			/** @var XmlConfigDomDocument $newdoc we explicitly requested this class above */
			$document = $newdoc;
			
			// save the old document URI just in case
			$document->documentURI = $documentUri;
		}
		
		return $document;
	}
	
	/**
	 * Transforms a given document according to xml-stylesheet processing
	 * instructions
	 * @param      XmlConfigDomDocument $document The document to act upon.
	 * @param      ?string $environment The environment name, or null if none is configured.
	 * @param      ?string $context The context name, or null if none is configured.
	 * @return     XmlConfigDomDocument The transformed document.
	 * @since      1.0.0
	 */
	public static function transformProcessingInstructions(XmlConfigDomDocument $document, $environment, ?string $context)
	{
		$transformations = [];
		$transformationInfo = [];
		
		// see if there are <?xml-stylesheet... processing instructions
		$stylesheetProcessingInstructions = $document->query("//processing-instruction('xml-stylesheet')", $document);
		foreach($stylesheetProcessingInstructions as $pi) {
			// The query selects processing-instruction() nodes only, and
			// registerNodeClass() guarantees they are always
			// XmlConfigDomProcessingInstruction, never a vanilla DOMNode.
			/** @var \Quiote\Config\Util\DOM\XmlConfigDomProcessingInstruction $pi */
			// yes! alright. trick: we create a doc fragment with the contents so we don't have to parse things by hand...
			$fragment = $document->createDocumentFragment();
			$fragment->appendXml('<foo ' . $pi->data . ' />');
			// registerNodeClass() guarantees the parsed element is a
			// XmlConfigDomElement, never a vanilla DOMNode.
			/** @var XmlConfigDomElement $firstChild */
			$firstChild = $fragment->firstChild;
			$type = $firstChild->getAttribute('type');
			// we process only the types below...
			if(in_array($type, ['text/xml', 'text/xsl', 'application/xml', 'application/xsl+xml'])) {
				$href = $firstChild->getAttribute('href', '');
				
				if(str_starts_with((string) $href, '#')) {
					// the href points to an embedded XSL stylesheet (with ID reference), so let's see if we can find it
					$stylesheets = $document->query("//*[@id='" . substr((string) $href, 1) . "']", $document);
					$stylesheetNode = $stylesheets->item(0);
					if($stylesheetNode instanceof \DOMNode) {
						// excellent. make a new doc from that element!
						try {
							$xsl = new XmlConfigDomDocument();
							$xsl->appendChild(self::requireImportedNode($xsl->importNode($stylesheetNode, true)));
						} catch(\DOMException $dome) {
							throw new ParseException(sprintf('Configuration file "%s" could not be parsed: Could not load XSL stylesheet "%s": %s', $document->documentURI, $href, $dome->getMessage()), 0, $dome);
						}
						
						// and append to the list of XSLs to process
						// TODO: spec mandates that external XSLs be processed first!
						$transformations[] = $xsl;
					} else {
						throw new ParseException(sprintf('Configuration file "%s" could not be parsed because the inline stylesheet "%s" referenced in the "xml-stylesheet" processing instruction could not be found in the document.', $document->documentURI, $href));
					}
				} else {
					// href references an xsl file, remember the path
					$transformationInfo[] = Toolkit::expandDirectives($href) ?? '';
				}
				
				// remove the processing instructions after we dealt with them
				if($pi->parentNode !== null) {
					$pi->parentNode->removeChild($pi);
				}
			}
		}
		
		return self::transform($document, $environment, $context, $transformationInfo, $transformations);
	}
	
	/**
	 * Perform validation on a given document.
	 * @param      XmlConfigDomDocument $document The document to act upon.
	 * @param      ?string $environment The environment name, or null if none is configured.
	 * @param      ?string $context The context name, or null if none is configured.
	 * @param      array<string,mixed> $validationInfo An array of validation information.
	 * @return     void
	 * @since      1.0.0
	 */
	public static function validate(XmlConfigDomDocument $document, $environment, ?string $context, array $validationInfo = [])
	{
		// bail out right away if validation is disabled
		if(Config::getBool('core.skip_config_validation', false)) {
			return;
		}
		
		$errors = [];
		
		foreach($validationInfo as $type => $files) {
			try {
				switch($type) {
					case self::VALIDATION_TYPE_XMLSCHEMA:
						self::validateXmlschema($document, self::stringList($files));
						break;
					case self::VALIDATION_TYPE_RELAXNG:
						self::validateRelaxng($document, self::stringList($files));
						break;
					case self::VALIDATION_TYPE_SCHEMATRON:
						self::validateSchematron($document, $environment, $context, self::stringList($files));
						break;
				}
			} catch(ParseException $e) {
				$errors[] = $e->getMessage();
			}
		}
		
		if($errors) {
			throw new ParseException(sprintf('Validation of configuration file "%s" failed:' . "\n\n%s", $document->documentURI, implode("\n\n", $errors)));
		}
	}

	/**
	 * Clean up a given document.
	 * @param      XmlConfigDomDocument $document The document to clean up.
	 * @return     void
	 * @since      1.0.0
	 */
	public static function cleanup(XmlConfigDomDocument $document)
	{
		// remove top-level <sandbox> element
		$sandbox = $document->getSandbox();
		if($sandbox !== null && $sandbox->parentNode !== null) {
			$sandbox->parentNode->removeChild($sandbox);
		}
	}
	
	/**
	 * Validate a given document according to XMLSchema-instance (xsi)
	 * declarations.
	 * @param      XmlConfigDomDocument $document The document to act upon.
	 * @return     void
	 * @since      1.0.0
	 */
	public static function validateXsi(XmlConfigDomDocument $document)
	{
		// next, find (and validate against) XML schema instance declarations
		$documentElement = self::requireDocumentElement($document);
		$sources = [];
		if($documentElement->hasAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'schemaLocation')) {
			// find locations. for namespaces, they are space separated pairs of a namespace URI and a schema location
			$locations = preg_split('/\s+/', $documentElement->getAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'schemaLocation') ?? '');
			if ($locations === false) {
				throw new ParseException(sprintf('Configuration file "%s" has a malformed xsi:schemaLocation attribute.', $document->documentURI));
			}
			for($i = 1; $i < count($locations); $i += 2) {
				$sources[] = $locations[$i];
			}
		}
		// no namespace? then it's only one schema location in this attribute
		$noNamespaceLocation = $documentElement->getAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'noNamespaceSchemaLocation');
		if($noNamespaceLocation !== null) {
			$sources[] = $noNamespaceLocation;
		}
		if($sources) {
			// we have instances to validate against...
			$schemas = [];
			foreach($sources as &$source) {
				// so for each location, we need to grab the file and validate against this grabbed source code, as libxml often has a hard time retrieving stuff over HTTP
				$source = Toolkit::expandDirectives($source) ?? '';
				if(parse_url($source, PHP_URL_SCHEME) === null && !Toolkit::isPathAbsolute($source)) {
					// the schema location is relative to the XML file
					$source = dirname((string) $document->documentURI) . DIRECTORY_SEPARATOR . $source;
				}
				$schema = @file_get_contents($source);
				if($schema === false) {
					throw new UnreadableException(sprintf('XML Schema validation file "%s" for configuration file "%s" does not exist or is unreadable', $source, $document->documentURI));
				}
				$schemas[] = $schema;
			}
			// now validate them all
			self::validateXmlschemaSource($document, $schemas);
		}
	}
	
	/**
	 * Validate the document against the given list of XML Schema files.
	 * @param      XmlConfigDomDocument $document The document to act upon.
	 * @param      array<int,string> $validationFiles An array of file names to validate against.
	 * @return     void
	 * @since      1.0.0
	 */
	public static function validateXmlschema(XmlConfigDomDocument $document, array $validationFiles = [])
	{
		foreach($validationFiles as $validationFile) {
			if(!is_readable($validationFile)) {
				throw new UnreadableException(sprintf('XML Schema validation file "%s" for configuration file "%s" does not exist or is unreadable', $validationFile, $document->documentURI));
			}
			
			try {
				$document->schemaValidate($validationFile);
			} catch(\DOMException $dome) {
				throw new ParseException(sprintf('XML Schema validation of configuration file "%s" failed:' . "\n\n%s", $document->documentURI, $dome->getMessage()), 0, $dome);
			}
		}
	}
	
	/**
	 * Validate the document against the given list of XML Schema documents.
	 * @param      XmlConfigDomDocument $document The document to act upon.
	 * @param      array<int,string> $validationSources An array of schema documents to validate against.
	 * @return     void
	 * @since      1.0.0
	 */
	public static function validateXmlschemaSource(XmlConfigDomDocument $document, array $validationSources = [])
	{
		foreach($validationSources as $validationSource) {
			try {
				$document->schemaValidateSource($validationSource);
			} catch(\DOMException $dome) {
				throw new ParseException(sprintf('XML Schema validation of configuration file "%s" failed:' . "\n\n%s", $document->documentURI, $dome->getMessage()), 0, $dome);
			}
		}
	}
	
	/**
	 * Validate the document against the given list of RELAX NG files.
	 * @param      XmlConfigDomDocument $document The document to act upon.
	 * @param      array<int,string> $validationFiles An array of file names to validate against.
	 * @return     void
	 * @since      1.0.0
	 */
	public static function validateRelaxng(XmlConfigDomDocument $document, array $validationFiles = [])
	{
		foreach($validationFiles as $validationFile) {
			if(!is_readable($validationFile)) {
				throw new UnreadableException(sprintf('RELAX NG validation file "%s" for configuration file "%s" does not exist or is unreadable', $validationFile, $document->documentURI));
			}
			
			try {
				$document->relaxNGValidate($validationFile);
			} catch(\DOMException $dome) {
				throw new ParseException(sprintf('RELAX NG validation of configuration file "%s" failed:' . "\n\n%s", $document->documentURI, $dome->getMessage()), 0, $dome);
			}
		}
	}
	
	/**
	 * Validate the document against the given list of Schematron files.
	 * @param      XmlConfigDomDocument $document The document to act upon.
	 * @param      ?string $environment The environment name, or null if none is configured.
	 * @param      ?string $context The context name, or null if none is configured.
	 * @param      array<int,string> $validationFiles An array of file names to validate against.
	 * @return     void
	 * @since      1.0.0
	 */
	public static function validateSchematron(XmlConfigDomDocument $document, $environment, ?string $context, array $validationFiles = [])
	{
		if(Config::getBool('core.skip_config_transformations', false)) {
			return;
		}
		
		// load the schematron processor
		$schematron = new SchematronProcessor();
		$schematron->setNode($document);
		// set some info (config file path, context name, environment name) as params
		// first arg is the namespace URI, which PHP doesn't support. awesome. see http://bugs.php.net/bug.php?id=30622 for the sad details
		// we could use "quiote:context" etc, that does work even without such a prefix being declared in the stylesheet, but that would be completely non-XML-ish, confusing, and against the spec. so we use dots instead.
		$schematron->setParameters([
			'quiote.config_path' => $document->documentURI,
			'quiote.environment' => $environment,
			'quiote.context' => $context,
		]);
		
		// loop over all validation files. those are .sch schematron schemas, which we transform to an XSL document that is then used to validate the source document :)
		foreach($validationFiles as $href) {
			if(!is_readable($href)) {
				throw new UnreadableException(sprintf('Schematron validation file "%s" for configuration file "%s" does not exist or is unreadable', $href, $document->documentURI));
			}
			
			// load the .sch file
			try {
				$sch = new XmlConfigDomDocument();
				$sch->load($href);
			} catch(\DOMException $dome) {
				throw new ParseException(sprintf('Schematron validation of configuration file "%s" failed: Could not load schema file "%s": %s', $document->documentURI, $href, $dome->getMessage()), 0, $dome);
			}
			
			// perform the validation transformation
			try {
				$result = $schematron->transform($sch);
			} catch(\Exception $e) {
				throw new ParseException(sprintf('Schematron validation of configuration file "%s" failed: Transformation failed: %s', $document->documentURI, $e->getMessage()), 0, $e);
			}
			
			// validation ran okay, now we need to look at the result document to see if there are errors
			// $result comes from XSLTProcessor::transformToDoc(), which returns a plain
			// \DOMDocument (not an XmlConfigDomDocument), so it has no getXpath() method.
			$xpath = new \DOMXPath($result);
			$xpath->registerNamespace('svrl', self::NAMESPACE_SVRL_ISO);

			$results = self::queryOrThrow($xpath, '/svrl:schematron-output/svrl:failed-assert/svrl:text');
			if($results->length) {
				$errors = ['Failed assertions:'];

				foreach($results as $result) {
					$errors[] = $result->nodeValue;
				}

				$results = self::queryOrThrow($xpath, '/svrl:schematron-output/svrl:successful-report/svrl:text');
				if($results->length) {
					$errors[] = '';
					$errors[] = 'Successful reports:';
					foreach($results as $result) {
						$errors[] = $result->nodeValue;
					}
				}
				
				throw new ParseException(sprintf('Schematron validation of configuration file "%s" failed:' . "\n\n%s", $document->documentURI, implode("\n", $errors)));
			}
		}
	}

	/**
	 * Run an XPath query and return the resulting node list.
	 *
	 * DOMXPath::query() returns `false` only when the expression itself is
	 * malformed -- every expression used here is a fixed, developer-authored
	 * string, never user input, so a `false` result signals a genuine bug
	 * in the calling code rather than a runtime condition to branch on.
	 * @return \DOMNodeList<\DOMNode|\DOMNameSpaceNode>
	 */
	private static function queryOrThrow(\DOMXPath $xpath, string $expression): \DOMNodeList
	{
		$result = $xpath->query($expression);

		if ($result === false) {
			throw new \LogicException(sprintf('Malformed XPath expression "%s".', $expression));
		}

		return $result;
	}

	/**
	 * Retrieve the document's root element, guaranteeing it is present.
	 * A document that was actually loaded (see the constructor, which fails
	 * fast on an unparseable file) always has a root element; this only
	 * throws if it is ever called on a document that was never successfully
	 * populated.
	 * @throws \Quiote\Exception\ParseException If the document has no root element.
	 */
	private static function requireDocumentElement(XmlConfigDomDocument $document): XmlConfigDomElement
	{
		$documentElement = $document->documentElement;

		if (!$documentElement instanceof XmlConfigDomElement) {
			throw new ParseException(sprintf('Configuration file "%s" has no root element.', $document->documentURI));
		}

		return $documentElement;
	}

	/**
	 * Records $imported's source position by walking $original (its
	 * pre-import source) and $imported (importNode(..., true)'s structural
	 * clone of it) in lockstep -- exact, since a deep import can only ever
	 * produce a 1:1 shaped copy. Only records a position when the original
	 * node itself still had a real line number ($original->getLineNo() > 0);
	 * a node that was already a clone/XSLT-synthesized node by the time it
	 * got here (e.g. because its handler declared legacy-upgrade
	 * <transformation> stylesheets) is left absent from the index rather
	 * than given a wrong or zeroed one.
	 * @since      1.0.0
	 */
	private static function correlatePosition(\DOMNode $original, \DOMNode $imported, ElementPositionIndex $positions): void
	{
		if ($original instanceof \DOMElement && $imported instanceof \DOMElement) {
			$line = $original->getLineNo();
			$file = $original->ownerDocument?->documentURI;
			if ($line > 0 && $file !== null) {
				$positions->record($imported, $file, $line);
			}
		}

		$originalChildren = $original->childNodes;
		$importedChildren = $imported->childNodes;
		$count = min($originalChildren->length, $importedChildren->length);
		for ($i = 0; $i < $count; $i++) {
			$originalChild = $originalChildren->item($i);
			$importedChild = $importedChildren->item($i);
			if ($originalChild !== null && $importedChild !== null) {
				self::correlatePosition($originalChild, $importedChild, $positions);
			}
		}
	}

	/**
	 * DOMDocument::importNode() is declared to return DOMNode|false; every
	 * call site here immediately appends/attaches the result, which would
	 * fail with an unclear native TypeError on `false`. Failing fast with a
	 * ParseException here matches how the rest of this class reports
	 * malformed-input conditions.
	 */
	private static function requireImportedNode(mixed $node): \DOMNode
	{
		if (!$node instanceof \DOMNode) {
			throw new ParseException('A node could not be imported into the merged configuration document.');
		}
		return $node;
	}

	/**
	 * Narrows a config-map value (e.g. $transformationInfo[STAGE_SINGLE] or
	 * $validationInfo[STEP_TRANSFORMATIONS_BEFORE]) that PHPStan can only
	 * see as mixed -- since none of these maps have array-shape literal
	 * keys -- to a plain array, defaulting a missing/malformed entry to
	 * empty exactly as an unguarded array access plus a native `array`
	 * parameter type hint already required at runtime.
	 * @return array<mixed>
	 */
	private static function arrayOrEmpty(mixed $value): array
	{
		return is_array($value) ? $value : [];
	}

	/**
	 * Same as arrayOrEmpty(), but for the string-list shape
	 * $transformationInfo[...]/a validation type's file list actually holds
	 * -- filtering out any non-string entries defensively rather than
	 * blindly casting them.
	 * @return list<string>
	 */
	private static function stringList(mixed $value): array
	{
		if (!is_array($value)) {
			return [];
		}
		return array_values(array_filter($value, 'is_string'));
	}

	/**
	 * Reproduces PHP's native (string) cast for the scalar|null shape
	 * Toolkit::literalize() actually returns for a string input (bool, int,
	 * float, string, or null), without casting a genuinely non-scalar mixed
	 * value the way PHPStan's stricter rules disallow.
	 */
	private static function scalarToString(mixed $value): string
	{
		return match (true) {
			is_string($value) => $value,
			is_int($value), is_float($value) => (string) $value,
			is_bool($value) => $value ? '1' : '',
			default => '',
		};
	}
}
