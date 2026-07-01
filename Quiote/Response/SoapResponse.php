<?php
namespace Quiote\Response;

use Quiote\Controller\OutputType;
use BadMethodCallException;

/**
 * SoapResponse handles SOAP Web Service responses using the PHP SOAP ext.
 * @since      1.0.0
 * @version    1.0.0
 */
class SoapResponse extends Response
{
	/**
	 * @var        mixed The content to send back with this response.
	 */
	protected $content = null;
	
	/**
	 * @var        array An array of SOAP headers to send with the response.
	 */
	protected $soapHeaders = [];
	
	/**
	 * Import response metadata (SOAP headers) from another response.
	 * @param      Response The other response to import information from.
	 * @since      1.0.0
	 */
	#[\Override]
    public function merge(Response $otherResponse)
	{
		parent::merge($otherResponse);
		
		if($otherResponse instanceof SoapResponse) {
			foreach($otherResponse->getSoapHeaders() as $soapHeader) {
				if(!$this->hasSoapHeader($soapHeader->namespace, $soapHeader->name)) {
					$this->addSoapHeader($soapHeader);
				}
			}
		}
	}
	
	/**
	 * Redirect externally. Not implemented here.
	 * @param      mixed Where to redirect.
	 * @throws     BadMethodCallException
	 * @since      1.0.0
	 */
	public function setRedirect($to): never
	{
		throw new BadMethodCallException('Redirects are not implemented for SOAP.');
	}
	
	/**
	 * Get info about the set redirect. Not implemented here.
	 * @return     array An assoc array of redirect info, or null if none set.
	 * @throws     BadMethodCallException
	 * @since      1.0.0
	 */
	public function getRedirect(): never
	{
		throw new BadMethodCallException('Redirects are not implemented for SOAP.');
	}

	/**
	 * Check if a redirect is set. Not implemented here.
	 * @return     bool true, if a redirect is set, otherwise false
	 * @throws     BadMethodCallException
	 * @since      1.0.0
	 */
	public function hasRedirect(): never
	{
		throw new BadMethodCallException('Redirects are not implemented for SOAP.');
	}

	/**
	 * Clear any set redirect information. Not implemented here.
	 * @throws     BadMethodCallException
	 * @since      1.0.0
	 */
	public function clearRedirect(): never
	{
		throw new BadMethodCallException('Redirects are not implemented for SOAP.');
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
		$this->content = null;
		return true;
	}
	
	/**
	 * Send all response data to the client.
	 * @since      1.0.0
	 */
	public function send(?OutputType $outputType = null)
	{
		$this->sendSoapHeaders();
		// don't send content, that's done by returning it from Controller::dispatch(), so SoapServer::handle() deals with the rest
		// $this->sendContent();
	}
	
	/**
	 * Clear all response data.
	 * @since      1.0.0
	 */
	public function clear()
	{
		$this->clearContent();
		$this->clearSoapHeaders();
	}
	
	/**
	 * Clear all SOAP headers from the response.
	 * @since      1.0.0
	 */
	public function clearSoapHeaders()
	{
		$this->soapHeaders = [];
	}
	
	/**
	 * Send SOAP Headers.
	 * @since      1.0.0
	 */
	public function sendSoapHeaders()
	{
		$server = $this->context->getController()->getSoapServer();
		
		foreach($this->soapHeaders as $soapHeader) {
			$server->addSoapHeader($soapHeader);
		}
	}
	
	/**
	 * Get an array of all SOAP headers set on this response.
	 * @return     array An array of SoapHeader objects.
	 * @since      1.0.0
	 */
	public function getSoapHeaders()
	{
		return $this->soapHeaders;
	}
	
	/**
	 * Get a SOAP Header from this response based on its namespace and name.
	 * @param      string The namespace of the SOAP header element.
	 * @param      string The name of the SOAP header element.
	 * @return     SoapHeader A SoapHeader, if found, otherwise null.
	 * @since      1.0.0
	 */
	public function getSoapHeader($namespace, $name)
	{
		if(($key = $this->searchSoapHeader($namespace, $name)) !== false) {
			return $this->soapHeaders[$key];
		}
	}
	
	/**
	 * Add a SOAP Header to this response.
	 * @param      \SoapHeader The SOAP header to set.
	 * @since      1.0.0
	 */
	public function addSoapHeader(\SoapHeader $soapHeader)
	{
		$this->removeSoapHeader($soapHeader->namespace, $soapHeader->name);
		$this->soapHeaders[] = $soapHeader;
	}
	
	/**
	 * Set a SOAP header into this response.
	 * This method has the same signature as PHP's SoapHeader->__construct().
	 * @param      string The namespace of the SOAP header element.
	 * @param      string The name of the SOAP header element.
	 * @param      mixed  A SOAP header's content. It can be a PHP value or a
	 *                    SoapVar object.
	 * @param      bool   Value of the mustUnderstand attribute of the SOAP header
	 *                    element.
	 * @param      mixed  Value of the actor attribute of the SOAP header element.
	 * @since      1.0.0
	 */
	public function setSoapHeader($namespace, $name, $data = null, $mustUnderstand = false, $actor = null)
	{
		if($actor === null) {
			$h = new \SoapHeader($namespace, $name, $data, $mustUnderstand);
		} else {
			$h = new \SoapHeader($namespace, $name, $data, $mustUnderstand, $actor);
		}
		$this->addSoapHeader($h);
	}
	
	/**
	 * Remove a SOAP Header from this response based on its namespace and name.
	 * @param      string The namespace of the SOAP header element.
	 * @param      string The name of the SOAP header element.
	 * @since      1.0.0
	 */
	public function removeSoapHeader($namespace, $name)
	{
		if(($key = $this->searchSoapHeader($namespace, $name)) !== false) {
			unset($this->soapHeaders[$key]);
		}
	}
	
	/**
	 * Check if a SOAP Header has been set based on its namespace and name.
	 * @param      string The namespace of the SOAP header element.
	 * @param      string The name of the SOAP header element.
	 * @return     bool true, if this SOAP header has been set, false otherwise.
	 * @since      1.0.0
	 */
	public function hasSoapHeader($namespace, $name)
	{
		return $this->searchSoapHeader($namespace, $name) !== false;
	}
	
	/**
	 * Find the key of a SOAP Header based on its namespace and name.
	 * @param      string The namespace of the SOAP header element.
	 * @param      string The name of the SOAP header element.
	 * @return     int The key of the SOAP header in the array, otherwise false.
	 * @since      1.0.0
	 */
	protected function searchSoapHeader($namespace, $name)
	{
		foreach($this->soapHeaders as $key => $soapHeader) {
			if($soapHeader->namespace == $namespace && $soapHeader->name == $name) {
				return $key;
			}
		}
		return false;
	}
}

?>