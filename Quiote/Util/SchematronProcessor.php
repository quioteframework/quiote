<?php
namespace Quiote\Util;

use Quiote\Exception\QuioteException;
use Quiote\Exception\ParseException;

/**
 * SchematronProcessor transforms DOM documents according to ISO Schematron
 * validation and transformation rules into a document containing successful
 * reports and failed assertions.
 * @since      1.0.0
 * @version    1.0.0
 */
class SchematronProcessor extends ParameterHolder
{
	const NAMESPACE_SCHEMATRON_ISO = 'http://purl.oclc.org/dsdl/schematron';
	
	const NAMESPACE_SVRL_ISO = 'http://purl.oclc.org/dsdl/svrl';
	
	const NAMESPACE_XSL_1999 = 'http://www.w3.org/1999/XSL/Transform';
	
	/**
	 * @var        array<int, string> The list of Schematron implementation paths to process.
	 */
	private $chain;

	/**
	 * @var        array<string, QuioteXsltProcessor> A cache of processor instances.
	 */
	protected static $processors = [];

	/**
	 * @var        array<int, string> The list of Schematron implementation paths to process.
	 */
	protected static $defaultChain = [
		'%core.quiote_dir%/Config/schematron/iso_dsdl_include.xsl',
		'%core.quiote_dir%/Config/schematron/iso_abstract_expand.xsl',
		'%core.quiote_dir%/Config/schematron/iso_svrl_for_xslt1.xsl'
	];
	
	/**
	 * @var        ?\DOMDocument The document the processor will work on.
	 */
	protected $node = null;
	
	/**
	 * Creates a new processor for transforming documents into a Schematron
	 * report.
	 * @param      ?array<int, string> $chain The list of Schematron implementation paths to process.
	 * @since      1.0.0
	 */
	public function __construct(?array $chain = null)
	{
		if($chain === null) {
			$chain = static::$defaultChain;
		}
		
		if(!$chain) {
			throw new QuioteException('Schematron processor chain must contain at least one path name.');
		}
		
		$expandedChain = [];
		foreach($chain as $path) {
			$expandedPath = \Quiote\Util\Toolkit::expandDirectives($path);
			if($expandedPath === null) {
				throw new QuioteException('Schematron processor chain contains a path that failed to expand.');
			}

			$expandedChain[] = $expandedPath;
		}

		$this->chain = $expandedChain;
	}
	
	/**
	 * Get an array of all processors.
	 * @return     array<int, QuioteXsltProcessor> An array of XsltProcessor instances.
	 * @since      1.0.0
	 */
	public function getProcessors()
	{
		$retval = [];
		foreach($this->chain as $path) {
			$retval[] = static::getProcessor($path);
		}
		return $retval;
	}
	
	/**
	 * Get a processor instance for the given XSLT path.
	 * @param      string $path The file path to the XSL template.
	 * @return     QuioteXsltProcessor The processor instance.
	 * @since      1.0.0
	 */
	protected static function getProcessor($path)
	{
		if(!isset(self::$processors[$path])) {
			$processorImpl = new \DOMDocument();
			$processorImpl->load($path);
			$processor = new QuioteXsltProcessor();
			$processor->importStylesheet($processorImpl);
			self::$processors[$path] = $processor;
		}
		
		return self::$processors[$path];
	}
	
	/**
	 * Sets the document that this processor will transform and validate.
	 * @param      \DOMDocument $node The document to use.
	 * @return     void
	 * @since      1.0.0
	 */
	public function setNode(\DOMDocument $node)
	{
		$this->node = $node;
	}
	
	/**
	 * Prepare the given processor for use.
	 * Sets all parameters from this processor class.
	 * @param      QuioteXsltProcessor $processor The processor to prepare.
	 * @return     void
	 * @since      1.0.0
	 */
	protected function prepareProcessor($processor)
	{
		// ensure everything is a string to make hhvm happy
		$processor->setParameter('', array_map(strval(...), $this->getParameters()));
	}
	
	/**
	 * Cleanup the given processor after use.
	 * Removes all parameters from this processor class.
	 * Cannot be done in SchematronProcessor::prepareProcessor(), which is
	 * why this must be called in transform().
	 * @param      QuioteXsltProcessor $processor The processor to clean up.
	 * @return     void
	 * @since      1.0.0
	 */
	protected function cleanupProcessor($processor)
	{
		foreach(array_keys($this->getParameters()) as $parameter) {
			$processor->removeParameter('', (string) $parameter);
		}
	}
	
	/**
	 * Validates the node against a given Schematron validation file.
	 * @param      \DOMDocument $schema The validator to use.
	 * @return     \DOMDocument The transformed validation document.
	 * @since      1.0.0
	 */
	public function transform(\DOMDocument $schema)
	{
		// do we even have a document?
		if($this->node === null) {
			throw new ParseException('Schema validation failed because no document could be parsed');
		}
		
		// is it an ISO Schematron file?
		if(!$schema->documentElement || $schema->documentElement->namespaceURI != self::NAMESPACE_SCHEMATRON_ISO) {
			throw new ParseException(sprintf('Schema file "%s" is invalid', $schema->documentURI));
		}
		
		// transform the .sch file to a validation stylesheet using the Schematron implementation
		$validatorImpl = $schema;
		$first = true;
		foreach($this->getProcessors() as $processor) {
			if($first) {
				// set some vars for the schema
				$this->prepareProcessor($processor);
			}
			try {
				$validatorImpl = $processor->transformToDoc($validatorImpl);
			} catch(\Exception $e) {
				if($first) {
					$this->cleanupProcessor($processor);
				}
				throw new ParseException(sprintf('Could not transform schema file "%s": %s', $schema->documentURI, $e->getMessage()), 0, $e);
			}
			if($first) {
				$this->cleanupProcessor($processor);
				$first = false;
			}
		}
		
		// it transformed fine. but did we get a proper stylesheet instance at all? wrong namespaces can lead to empty docs that only have an XML prolog
		if(!$validatorImpl instanceof \DOMDocument) {
			throw new ParseException(sprintf('Processing using schema file "%s" resulted in no stylesheet document', $schema->documentURI));
		}
		if(!$validatorImpl->documentElement || $validatorImpl->documentElement->namespaceURI != self::NAMESPACE_XSL_1999) {
			throw new ParseException(sprintf('Processing using schema file "%s" resulted in an invalid stylesheet', $schema->documentURI));
		}
		
		// all fine so far. let us import the stylesheet
		try {
			$validator = new QuioteXsltProcessor();
			$validator->importStylesheet($validatorImpl);
		} catch(\Exception $e) {
			throw new ParseException(sprintf('Could not process the schema file "%s": %s', $schema->documentURI, $e->getMessage()), 0, $e);
		}
		
		// run the validation by transforming our document using the generated validation stylesheet
		try {
			$result = $validator->transformToDoc($this->node);
		} catch(\Exception $e) {
			throw new ParseException(sprintf('Could not validate the document against the schema file "%s": %s', $schema->documentURI, $e->getMessage()), 0, $e);
		}

		if(!$result instanceof \DOMDocument) {
			throw new ParseException(sprintf('Validating the document against the schema file "%s" resulted in no validation document', $schema->documentURI));
		}

		return $result;
	}
}

?>