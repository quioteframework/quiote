<?php
namespace Quiote\Response;

use Quiote\Controller\OutputType;
use BadMethodCallException;

/**
 * ConsoleResponse handles command line responses.
 * @since      1.0.0
 * @version    1.0.0
 */
class ConsoleResponse extends Response
{
	/**
	 * @var        string The content to send back with this response.
	 */
	protected $content = '';
	
	/**
	 * @var        int The shell exit code.
	 */
	protected $exitCode = 0;
	
	/**
	 * Import response metadata (nothing in this case) from another response.
	 * @param      Response $otherResponse The other response to import information from.
	 * @since      1.0.0
	 */
	#[\Override]
    public function merge(Response $otherResponse)
	{
		parent::merge($otherResponse);
	}
	
	/**
	 * Redirect externally. Not implemented here.
	 * @param      mixed $to Where to redirect.
	 * @throws     BadMethodCallException
	 * @since      1.0.0
	 */
	public function setRedirect($to): never
	{
		throw new BadMethodCallException('Redirects are not implemented for Console.');
	}
	
	/**
	 * Get info about the set redirect. Not implemented here.
	 * @return     never
	 * @throws     BadMethodCallException
	 * @since      1.0.0
	 */
	public function getRedirect(): never
	{
		throw new BadMethodCallException('Redirects are not implemented for Console.');
	}

	/**
	 * Check if a redirect is set. Not implemented here.
	 * @return     never
	 * @throws     BadMethodCallException
	 * @since      1.0.0
	 */
	public function hasRedirect(): never
	{
		throw new BadMethodCallException('Redirects are not implemented for Console.');
	}

	/**
	 * Clear any set redirect information. Not implemented here.
	 * @throws     BadMethodCallException
	 * @since      1.0.0
	 */
	public function clearRedirect(): never
	{
		throw new BadMethodCallException('Redirects are not implemented for Console.');
	}
	
	/**
	 * Set the shell exit code of this response.
	 * @param      int $exitCode The exit code.
	 * @since      1.0.0
	 */
	public function setExitCode($exitCode)
	{
		$this->exitCode = (int)$exitCode;
	}
	
	/**
	 * Get the shell exit code of this response.
	 * @return     int The exit code.
	 * @since      1.0.0
	 */
	public function getExitCode()
	{
		return $this->exitCode;
	}
	
	/**
	 * Determine whether the content in the response may be modified by appending
	 * or prepending data using string operations. Typically false for streams
	 * or responses where the content is not a string (e.g. an array).
	 * @return     bool If the content can be treated as / changed like a string.
	 * @since      1.0.0
	 */
	#[\Override]
    public function isContentMutable()
	{
		return true;
	}
	
	/**
	 * Send all response data to the client.
	 * @since      1.0.0
	 */
	public function send(?OutputType $outputType = null)
	{
		$this->sendContent();
		
		register_shutdown_function([$this, 'sendExit']);
	}
	
	/**
	 * Clear all response data.
	 * @since      1.0.0
	 */
	public function clear()
	{
		$this->clearContent();
		$this->setExitCode(0);
	}
	
	/**
	 * Send the content for this response
	 * @since      1.0.0
	 */
	#[\Override]
    protected function sendContent()
	{
		$isContentMutable = $this->isContentMutable();
		
		parent::sendContent();
		
		if($isContentMutable && $this->getParameter('append_eol', true)) {
			echo PHP_EOL;
		}
	}
	
	/**
	 * Call exit() and submit the exit code.
	 * This is called by PHP during script shutdown.
	 * It gets registered as a shutdown function in ConsoleResponse::send().
	 * @since      1.0.0
	 */
	public function sendExit(): never
	{
		exit($this->exitCode);
	}

	/**
	 * Reset console response state for FrankenPHP worker compatibility.
	 * Clears console-specific response properties that could leak between requests.
	 * @since      1.0.0
	 */
	#[\Override]
    public function reset(): void
	{
		// Reset console-specific properties
		$this->exitCode = 0;
		
		// Note: content is handled by parent reset
		
		// Call parent reset to clear base response properties
		parent::reset();
	}
}

?>