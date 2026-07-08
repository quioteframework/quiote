<?php
namespace Quiote\Response;
/**
 * Response handles the output and other stuff sent back to the client.
 * @since      1.0.0
 * @version    1.0.0
 */
use Quiote\Controller\OutputType;
use Quiote\Exception\InitializationException;
use Quiote\Util\AttributeHolder;
use Quiote\Context;
use Symfony\Contracts\Service\ResetInterface;
abstract class Response extends AttributeHolder implements ResetInterface
{

	/**
	 * @var        ?string
	 */
	protected final $contextName;

	/**
	 * @var        ?string
	 */
	protected final $outputTypeName;

	/**
	 * @var        ?array<string, mixed>
	 */
	protected final $contentStreamMeta;

	/**
	 * @var        ?Context An Context instance.
	 */
	protected $context = null;
	
	/**
	 * @var        mixed The content to send back to the client.
	 */
	protected $content = null;
	
	/**
	 * @var        ?OutputType The output type of this response.
	 */
	protected $outputType = null;
	
	/**
	 * Pre-serialization callback.
	 * Will set the name of the context and exclude the instance from serializing.
	 * @since      1.0.0
	 */
	public function __sleep()
	{
		// Collect all current object vars first.
		$vars = get_object_vars($this);
		// Replace heavy object references with lightweight identifiers.
		$this->contextName = $this->context?->getName();
		unset($vars['context']); // remove context instance so it won't be serialized
		
		if($this->outputType) {
			$this->outputTypeName = $this->outputType->getName();
			unset($vars['outputType']);
		}
		
		if(is_resource($this->content)) {
			$this->contentStreamMeta = stream_get_meta_data($this->content);
			unset($vars['content']);
		}
		// Returning just the keys of $vars now includes contextName/outputTypeName/contentStreamMeta
		// exactly once (they remain part of $vars as properties on $this) avoiding duplicates.
		return array_keys($vars);
	}
	
	/**
	 * Post-unserialization callback.
	 * Will restore the context based on the names set by __sleep.
	 * @since      1.0.0
	 */
	public function __wakeup()
	{
		$this->context = Context::getInstance($this->contextName);
		unset($this->contextName);
		
		if(isset($this->outputTypeName)) {
			$this->outputType = $this->context->getController()->getOutputType($this->outputTypeName);
			unset($this->outputTypeName);
		}
		
		if(isset($this->contentStreamMeta)) {
			// contrary to what the documentation says, stream_get_meta_data() will not return a list of filters attached to the stream, so we cannot restore these, unfortunately.
			$this->content = fopen($this->contentStreamMeta['uri'], $this->contentStreamMeta['mode']);
			unset($this->contentStreamMeta);
		}
	}
	
	/**
	 * Retrieve the Context instance this Response object belongs to.
	 * @return     Context An Context instance.
	 * @throws     InitializationException If this Response has not been initialized yet.
	 * @since      1.0.0
	 */
	public final function getContext()
	{
		if ($this->context === null) {
			throw new InitializationException(sprintf('%s has not been initialized; call initialize() first.', static::class));
		}
		return $this->context;
	}
	
	/**
	 * Initialize this Response.
	 * @param      Context $context An Context instance.
	 * @param      array<string, mixed> $parameters An array of initialization parameters.
	 * @return     void
	 * @since      1.0.0
	 */
	public function initialize(Context $context, array $parameters = [])
	{
		$this->context = $context;
		$this->setParameters($parameters);
	}
	
	/**
	 * Get the Output Type to use with this response.
	 * @return     ?OutputType The Output Type instance associated with, or null if none is set.
	 * @since      1.0.0
	 */
	public function getOutputType()
	{
		return $this->outputType;
	}
	
	/**
	 * Set the Output Type to use with this response.
	 * @param      OutputType $outputType The Output Type instance to associate with.
	 * @return     void
	 * @since      1.0.0
	 */
	public function setOutputType(OutputType $outputType)
	{
		$this->outputType = $outputType;
	}
	
	/**
	 * Clear the Output Type to use with this response.
	 * @return     void
	 * @since      1.0.0
	 */
	public function clearOutputType()
	{
		$this->outputType = null;
	}
	
	/**
	 * Retrieve the content set for this Response.
	 * @return     mixed The content set in this Response.
	 * @since      1.0.0
	 */
	public function getContent()
	{
		return $this->content;
	}
	
	/**
	 * Check whether or not some content is set.
	 * @return     bool If any content is set, false otherwise.
	 * @since      1.0.0
	 */
	public function hasContent()
	{
		return $this->content !== null;
	}
	
	/**
	 * Retrieve the size (in bytes) of the content set for this Response.
	 * @return     int|false The content size in bytes, or false if it could not be determined.
	 * @since      1.0.0
	 */
	public function getContentSize()
	{
		if(is_resource($this->content)) {
			if(($stat = fstat($this->content)) !== false) {
				return $stat['size'];
			} else {
				return false;
			}
		} else {
			return strlen((string) $this->content);
		}
	}
	
	/**
	 * Set the content for this Response.
	 * @param      mixed $content The content to be sent in this Response.
	 * @return     void
	 * @since      1.0.0
	 */
	public function setContent($content)
	{
		$this->content = $content;
	}
	
	/**
	 * Prepend content to the existing content for this Response.
	 * @param      mixed $content The content to be prepended to this Response.
	 * @return     void
	 * @since      1.0.0
	 */
	public function prependContent($content)
	{
		$this->setContent($content . $this->getContent());
	}
	
	/**
	 * Append content to the existing content for this Response.
	 * @param      mixed $content The content to be appended to this Response.
	 * @return     void
	 * @since      1.0.0
	 */
	public function appendContent($content)
	{
		$this->setContent($this->getContent() . $content);
	}
	
	/**
	 * Clear the content for this Response
	 * @return     void
	 * @since      1.0.0
	 */
	public function clearContent()
	{
		$this->content = null;
	}
	
	/**
	 * Redirect externally.
	 * @param      mixed $to Where to redirect.
	 * @return     void
	 * @since      1.0.0
	 */
	abstract public function setRedirect($to);

	/**
	 * Get info about the set redirect.
	 * @return     ?array<string, mixed> An assoc array of redirect info, or null if none set.
	 * @since      1.0.0
	 */
	abstract public function getRedirect();

	/**
	 * Check if a redirect is set.
	 * @return     bool true, if a redirect is set, otherwise false
	 * @since      1.0.0
	 */
	abstract public function hasRedirect();

	/**
	 * Clear any set redirect information.
	 * @return     void
	 * @since      1.0.0
	 */
	abstract public function clearRedirect();

	/**
	 * Import response metadata from another response.
	 * @param      Response $otherResponse The other response to import information from.
	 * @return     void
	 * @since      1.0.0
	 */
	public function merge(Response $otherResponse)
	{
		foreach($otherResponse->getAttributeNamespaces() as $namespace) {
			foreach($otherResponse->getAttributes($namespace) as $name => $value) {
				if(!$this->hasAttribute($name, $namespace)) {
					$this->setAttribute($name, $value, $namespace);
				} elseif(is_array($value)) {
					$thisAttribute =& $this->getAttribute($name, $namespace);
					if(is_array($thisAttribute)) {
						$thisAttribute = array_merge($value, $thisAttribute);
					}
				}
			}
		}
	}
	
	/**
	 * Clear all data for this Response.
	 * @return     void
	 * @since      1.0.0
	 */
	abstract public function clear();

	/**
	 * Send all response data to the client.
	 * @param      OutputType $outputType An optional Output Type object with information
	 *                             the response can use to send additional data.
	 * @return     void
	 * @since      1.0.0
	 */
	abstract public function send(?OutputType $outputType = null);
	
	/**
	 * Determine whether the content in the response may be modified by appending
	 * or prepending data using string operations. Typically false for streams
	 * or responses where the content is not a string (e.g. an array).
	 * @return     bool If the content can be treated as / changed like a string.
	 * @since      1.0.0
	 */
	public function isContentMutable()
	{
		return !$this->hasRedirect() && !is_resource($this->content);
	}
	
	/**
	 * Send the content for this response
	 * @return     void
	 * @since      1.0.0
	 */
	protected function sendContent()
	{
		if(is_resource($this->content)) {
			fpassthru($this->content);
			fclose($this->content);
		} else {
			echo $this->content;
		}
	}

	/**
	 * Reset response state for FrankenPHP worker compatibility.
	 * Clears response-specific properties that could leak between requests.
	 * @since      1.0.0
	 */
	#[\Override]
    public function reset(): void
	{
		$this->contextName = null;
		$this->outputTypeName = null;
		$this->contentStreamMeta = null;
		$this->context = null;
		$this->content = null;
		$this->outputType = null;
		
		// Reset parent attribute holder state (which includes parameters)
		parent::clearAttributes();
		
		// Also clear parameters that might exist at the parameter holder level
		$this->clearParameters();
	}
}

?>