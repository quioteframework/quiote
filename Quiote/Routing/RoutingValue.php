<?php
namespace Quiote\Routing;

use Quiote\Context;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Routing values are used internally and, optionally, by users in gen() calls
 * and callbacks to have more control over encoding behavior and values in pre-
 * and postfixes
 * @since      1.0.0
 * @version    1.0.0
 */
class RoutingValue implements IRoutingValue, ResetInterface
{
	protected $context;
	protected $contextName;
	protected $prefix;
	protected $postfix;
	protected $prefixNeedsEncoding = false;
	protected $postfixNeedsEncoding = false;
	protected $valueEncoded;
	protected $postfixEncoded;
	protected $prefixEncoded;
	
	protected static $arrayMap = [
		'pre'  => 'prefix',
		'val'  => 'value',
		'post' => 'postfix',
	];
	
	/**
	 * Constructor.
	 * @param      mixed The value.
	 * @param      bool  Whether or not the value needs encoding.
	 * @since      1.0.0
	 */
	public function __construct(protected $value, protected $valueNeedsEncoding = true)
    {
    }
	
	/**
	 * Pre-serialization callback.
	 * Will set the name of the context instead of the instance, which will later
	 * be restored by __wakeup().
	 * @since      1.0.0
	 */
	public function __sleep()
	{
		$this->contextName = $this->context->getName();
		$arr = get_object_vars($this);
		unset($arr['context']);
		return array_keys($arr);
	}

	/**
	 * Post-unserialization callback.
	 * Will restore the context instance based on their names set by __sleep().
	 * @since      1.0.0
	 */
	public function __wakeup()
	{
		$this->context = Context::getInstance($this->contextName);
		
		unset($this->contextName);
	}
	
	/**
	 * Initialize the routing value.
	 * @param      Context The Context.
	 * @param      array        An array of initialization parameters.
	 * @since      1.0.0
	 */
	public function initialize(Context $context, array $parameters = [])
	{
		$this->context = $context;
	}
	
	/**
	 * Set the value.
	 * @param      mixed The value.
	 * @since      1.0.0
	 */
	public function setValue($value)
	{
		$this->value = $value;
		return $this;
	}
	
	/**
	 * Retrieve the value.
	 * @param      mixed The value.
	 * @since      1.0.0
	 */
	public function getValue()
	{
		return $this->value;
	}
	
	/**
	 * Set the prefix.
	 * @param      string The prefix.
	 * @since      1.0.0
	 */
	public function setPrefix($value)
	{
		$this->prefix = $value;
		return $this;
	}
	
	/**
	 * Retrieve the prefix.
	 * @return     string The prefix.
	 * @since      1.0.0
	 */
	public function getPrefix()
	{
		return $this->prefix;
	}
	
	/**
	 * Check if a prefix is set.
	 * @return     bool True, if a prefix is set, false otherwise.
	 * @since      1.0.0
	 */
	public function hasPrefix()
	{
		return $this->prefix !== null;
	}
	
	/**
	 * Set the postfix.
	 * @param      string The postfix.
	 * @since      1.0.0
	 */
	public function setPostfix($value)
	{
		$this->postfix = $value;
		return $this;
	}
	
	/**
	 * Retrieve the postfix.
	 * @return     string The postfix.
	 * @since      1.0.0
	 */
	public function getPostfix()
	{
		return $this->postfix;
	}
	
	/**
	 * Check if a postfix is set.
	 * @return     bool True, if a postfix is set, false otherwise.
	 * @since      1.0.0
	 */
	public function hasPostfix()
	{
		return $this->postfix !== null;
	}
	
	/**
	 * Set whether or not the value needs to be encoded.
	 * @param      bool True, if the postfix needs encoding, false otherwise.
	 * @since      1.0.0
	 */
	public function setValueNeedsEncoding($needsEncoding)
	{
		$this->valueNeedsEncoding = $needsEncoding;
		return $this;
	}
	
	/**
	 * Retrieve whether or not the value needs to be encoded.
	 * @return     bool True, if the value needs encoding, false otherwise.
	 * @since      1.0.0
	 */
	public function getValueNeedsEncoding()
	{
		return $this->valueNeedsEncoding;
	}
	
	/**
	 * Set whether or not the prefix needs to be encoded.
	 * @param      bool True, if the prefix needs encoding, false otherwise.
	 * @since      1.0.0
	 */
	public function setPrefixNeedsEncoding($needsEncoding)
	{
		$this->prefixNeedsEncoding = $needsEncoding;
		return $this;
	}
	
	/**
	 * Retrieve whether or not the prefix needs to be encoded.
	 * @return     bool True, if the prefix needs encoding, false otherwise.
	 * @since      1.0.0
	 */
	public function getPrefixNeedsEncoding()
	{
		return $this->prefixNeedsEncoding;
	}
	
	/**
	 * Set whether or not the postfix needs to be encoded.
	 * @param      bool True, if the postfix needs encoding, false otherwise.
	 * @since      1.0.0
	 */
	public function setPostfixNeedsEncoding($needsEncoding)
	{
		$this->postfixNeedsEncoding = $needsEncoding;
		return $this;
	}
	
	/**
	 * Retrieve whether or not the postfix needs to be encoded.
	 * @return     bool True, if the postfix needs encoding, false otherwise.
	 * @since      1.0.0
	 */
	public function getPostfixNeedsEncoding()
	{
		return $this->postfixNeedsEncoding;
	}
	
	/**
	 * Check if this routing value is equal to the given parameter.
	 * @param      mixed The value to compare $this against.
	 * @return     bool Whether the value matches $this.
	 * @since      1.0.0
	 */
	public function equals($other)
	{
		if($other instanceof self) {
			return $this == $other;
		} elseif(is_array($other)) {
			return $this->value == $other['val'] && $this->prefix == $other['pre'] && $this->postfix == $other['post'] && !$this->valueEncoded && $this->prefixEncoded && $this->postfixEncoded;
		} else {
			return $this->prefix === null && $this->postfix === null && $this->value == $other && !$this->valueEncoded;
		}
	}
	
	/**
	 * ArrayAccess method for isset().
	 * @param      mixed The offset.
	 * @return     bool Whether or not the given offset exists.
	 * @since      1.0.0
	 */
	public function offsetExists($offset): bool
	{
		return isset(self::$arrayMap[$offset]);
	}
	
	/**
	 * ArrayAccess method for getting a value.
	 * @param      mixed The offset.
	 * @return     mixed The value, nor null if the value does not exist.
	 * @since      1.0.0
	 */
	public function offsetGet($offset): mixed
	{
		if(isset(self::$arrayMap[$offset])) {
			return $this->{self::$arrayMap[$offset]};
		}
		return null;
	}
	
	/**
	 * ArrayAccess method for setting a value.
	 * @param      mixed The offset.
	 * @param      mixed The value.
	 * @since      1.0.0
	 */
	public function offsetSet($offset, $value): void
	{
		if(isset(self::$arrayMap[$offset])) {
			$this->{self::$arrayMap[$offset]} = $value;
		}
	}
	
	/**
	 * ArrayAccess method for unset().
	 * @param      mixed The offset.
	 * @since      1.0.0
	 */
	public function offsetUnset($offset): void
	{
		if(isset(self::$arrayMap[$offset])) {
			$this->{self::$arrayMap[$offset]} = null;
		}
	}
	
	/**
	 * Return the encoded value (without pre- or postfix) for BC.
	 * @return     string The encoded value.
	 * @since      1.0.0
	 */
	public function __toString(): string
	{
		return (string) $this->context->getRouting()->escapeOutputParameter($this->value);
	}

	public function reset(): void
	{
		$this->context = null;
		$this->contextName = null;
		$this->prefix = null;
		$this->postfix = null;
		$this->prefixNeedsEncoding = false;
		$this->postfixNeedsEncoding = false;
		$this->valueEncoded = false;
		$this->postfixEncoded = false;
		$this->prefixEncoded = false;
		
		unset($this->value);
		unset($this->valueNeedsEncoding);
		
		unset(self::$arrayMap);
	}
}

?>