<?php
namespace Quiote\Response;

use Quiote\Controller\OutputType;
use BadMethodCallException;

/**
 * XmlrpcepiphpResponse handles XMLRPC Web Service responses using the
 * XMLRPC-EPI extension for PHP.
 * @since      1.0.0
 * @version    1.0.0
 */
class XmlrpcepiphpResponse extends Response
{
	/**
	 * @var        array The content to send back with this response.
	 */
	protected $content = [];
	

	protected $httpHeaders = [];
	protected $cookies = [];

	/**
	 * Check whether or not some content is set.
	 * @return     bool If any content is set, false otherwise.
	 * @since      1.0.0
	 */
	#[\Override]
    public function hasContent()
	{
		return $this->content !== [];
	}
	
	/**
	 * Set the content for this Response.
	 * @see        Response::setContent()
	 * @param      array The content to be sent in this Response.
	 * @return     bool Whether or not the operation was successful.
	 * @since      1.0.0
	 */
	#[\Override]
    public function setContent($content)
	{
		return parent::setContent((array) $content);
	}
	
	/**
	 * Prepend content to the existing content for this Response.
	 * @param      array The content to be prepended to this Response.
	 * @return     bool Whether or not the operation was successful.
	 * @since      1.0.0
	 */
	#[\Override]
    public function prependContent($content)
	{
		return $this->setContent((array) $content + $this->getContent());
	}
	
	/**
	 * Append content to the existing content for this Response.
	 * @param      array The content to be appended to this Response.
	 * @return     bool Whether or not the operation was successful.
	 * @since      1.0.0
	 */
	#[\Override]
    public function appendContent($content)
	{
		return $this->setContent($this->getContent() + (array) $content);
	}
	
	/**
	 * Import response metadata (nothing in this case) from another response.
	 * @param      Response The other response to import information from.
	 * @since      1.0.0
	 */
	#[\Override]
    public function merge(Response $otherResponse)
	{
		parent::merge($otherResponse);
	}
	
	/**
	 * Redirect externally. Not implemented here.
	 * @param      mixed Where to redirect.
	 * @throws     BadMethodCallException
	 * @since      1.0.0
	 */
	public function setRedirect($to): never
	{
		throw new BadMethodCallException('Redirects are not implemented for XMLRPC.');
	}
	
	/**
	 * Get info about the set redirect. Not implemented here.
	 * @return     array An assoc array of redirect info, or null if none set.
	 * @throws     BadMethodCallException
	 * @since      1.0.0
	 */
	public function getRedirect(): never
	{
		throw new BadMethodCallException('Redirects are not implemented for XMLRPC.');
	}

	/**
	 * Check if a redirect is set. Not implemented here.
	 * @return     bool true, if a redirect is set, otherwise false
	 * @throws     BadMethodCallException
	 * @since      1.0.0
	 */
	public function hasRedirect(): never
	{
		throw new BadMethodCallException('Redirects are not implemented for XMLRPC.');
	}

	/**
	 * Clear any set redirect information. Not implemented here.
	 * @throws     BadMethodCallException
	 * @since      1.0.0
	 */
	public function clearRedirect(): never
	{
		throw new BadMethodCallException('Redirects are not implemented for XMLRPC.');
	}
	
	/**
	 * @see        Response::isMutable()
	 * @since      1.0.0
	 */
	#[\Override]
    public function isContentMutable()
	{
		return false;
	}
	
	/**
	 * Clear the content for this Response
	 * @return     bool Whether or not the operation was successful.
	 * @since      1.0.0
	 */
	#[\Override]
    public function clearContent()
	{
		$this->content = [];
		return true;
	}
	
	/**
	 * Send all response data to the client.
	 * @since      1.0.0
	 */
	public function send(?OutputType $outputType = null)
	{
		$encoding = ['encoding' => $this->getParameter('output_options[encoding]', 'utf-8')];
		if($outputType) {
			$encoding = ['encoding' => $outputType->getParameter('encoding', $encoding['encoding'])];
		}
		
		$outputOptions = array_merge(['escaping' => ['markup', 'non-print']], (array) $this->getParameter('output_options', []), $encoding);
		
		$this->content = xmlrpc_encode_request(null, $this->content, $outputOptions);
		
		header('Content-Type: text/xml; charset=' . $outputOptions['encoding']);
//		header('Content-Length: ' . strlen($this->content));
		
		$this->sendContent();
	}
	
	/**
	 * Clear all response data.
	 * @since      1.0.0
	 */
	public function clear()
	{
		$this->clearContent();
		$this->content = [];
		$this->httpHeaders = [];
		$this->cookies = [];
	}
}

?>