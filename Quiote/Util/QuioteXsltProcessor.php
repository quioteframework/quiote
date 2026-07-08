<?php
namespace Quiote\Util;

use XSLTProcessor;

/**
 * Extended XSLTProcessor class that throws exceptions on errors.
 * @since      1.0.0
 * @version    1.0.0
 */
class QuioteXsltProcessor extends \XSLTProcessor
{
	/**
	 * Import a stylesheet.
	 * @param      \DOMDocument $stylesheet The stylesheet to import.
	 * @since      1.0.0
	 */
	public function importStylesheet($stylesheet): bool
	{
		$luie = libxml_use_internal_errors(true);
		libxml_clear_errors();
		
		$retVal = parent::importStylesheet($stylesheet);
		
		// libxml_get_last_error() returns false if importStylesheet failed, libxml_get_errors() works nontheless. zomfg libxml.
		// also, if we catch the errors here and throw an exception, we don't need an @ further down at transformToDoc().
		if(libxml_get_last_error() !== false || count(libxml_get_errors())) {
			$errors = [];
			foreach(libxml_get_errors() as $error) {
				$errors[] = sprintf('[%s #%d] Line %d: %s', $error->level == LIBXML_ERR_WARNING ? 'Warning' : ($error->level == LIBXML_ERR_ERROR ? 'Error' : 'Fatal'), $error->code, $error->line, $error->message);
			}
			libxml_clear_errors();
			libxml_use_internal_errors($luie);
			throw new \Exception(
				sprintf(
					'Error%s occurred while importing the stylesheet "%s": ' . "\n\n%s",
					count($errors) > 1 ? 's' : '', 
					$stylesheet->documentURI,
					implode("\n", $errors)
				)
			);
		}
		
		libxml_use_internal_errors($luie);
		return $retVal;
	}
	
	/**
	 * Transform a document with a stylesheet.
	 * @param      mixed $doc The document to transform; must be a DOMDocument or SimpleXMLElement.
	 * @return     object The resulting DOMDocument (or subclass of $doc's owner document).
	 * @since      1.0.0
	 */
	public function transformToDoc($doc, $returnClass = null): false|object
	{
		if (!$doc instanceof \DOMDocument && !$doc instanceof \SimpleXMLElement) {
			throw new \InvalidArgumentException(sprintf(
				'%s::transformToDoc() requires a DOMDocument or SimpleXMLElement, %s given.',
				self::class,
				get_debug_type($doc)
			));
		}

		$luie = libxml_use_internal_errors(true);
		libxml_clear_errors();

		$result = parent::transformToDoc($doc, $returnClass);
		
		// check if result is false, too, as that means the transformation failed for reasons like infinite template recursion
		if($result === false || libxml_get_last_error() !== false || count(libxml_get_errors())) {
			$errors = [];
			foreach(libxml_get_errors() as $error) {
				$errors[] = sprintf('[%s #%d] Line %d: %s', $error->level == LIBXML_ERR_WARNING ? 'Warning' : ($error->level == LIBXML_ERR_ERROR ? 'Error' : 'Fatal'), $error->code, $error->line, $error->message);
			}
			libxml_clear_errors();
			libxml_use_internal_errors($luie);
			throw new \Exception(
				sprintf(
					'Error%s occurred while transforming the document using an XSL stylesheet: ' . "\n\n%s", 
					count($errors) > 1 ? 's' : '', 
					implode("\n", $errors)
				)
			);
		}
		
		libxml_use_internal_errors($luie);

		// turn this into an instance of the class that was passed in, rather than a regular DOMDocument;
		// SimpleXMLElement has no DOMDocument-compatible owner to preserve, so it always falls back to DOMDocument
		if($doc instanceof \DOMDocument) {
			$documentClass = $doc::class;
			$document = new $documentClass();
		} else {
			$document = new \DOMDocument();
		}
		$serialized = $result->saveXML();
		if($serialized === false) {
			throw new \Exception('Failed to serialize the transformation result to XML.');
		}
		$document->loadXML($serialized);

		// save the URI just in case
		$document->documentURI = $result->documentURI;
		
		unset($result);
		
		return $document;
	}
}

?>