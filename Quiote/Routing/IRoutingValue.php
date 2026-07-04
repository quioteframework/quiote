<?php
namespace Quiote\Routing;

use Quiote\Context;

/**
 * Routing values are used internally and, optionally, by users in gen() calls
 * and callbacks to have more control over encoding behavior and values in pre-
 * and postfixes
 * @since      1.0.0
 * @version    1.0.0
 */
interface IRoutingValue extends \ArrayAccess
{
	/**
	 * Constructor.
	 * @param      mixed $value The value.
	 * @param      bool  $valueNeedsEncoding Whether or not the value needs encoding.
	 * @since      1.0.0
	 */
	public function __construct($value, $valueNeedsEncoding = true);
	
	/**
	 * Pre-serialization callback.
	 * Will set the name of the context instead of the instance, which will later
	 * be restored by __wakeup().
	 * @since      1.0.0
	 */
	public function __sleep();

	/**
	 * Post-unserialization callback.
	 * Will restore the context instance based on their names set by __sleep().
	 * @since      1.0.0
	 */
	public function __wakeup();
	
	/**
	 * Initialize the routing value.
	 * @param      Context $context The Context.
	 * @param      array        $parameters An array of initialization parameters.
	 * @since      1.0.0
	 */
	public function initialize(Context $context, array $parameters = []);

	/**
	 * Set the value.
	 * @param      mixed $value The value.
	 * @since      1.0.0
	 */
	public function setValue($value);

	/**
	 * Retrieve the value.
	 * @return     mixed The value.
	 * @since      1.0.0
	 */
	public function getValue();
	
	/**
	 * Set the prefix.
	 * @param      string $value The prefix.
	 * @since      1.0.0
	 */
	public function setPrefix($value);
	
	/**
	 * Retrieve the prefix.
	 * @return     string The prefix.
	 * @since      1.0.0
	 */
	public function getPrefix();
	
	/**
	 * Check if a prefix is set.
	 * @return     bool True, if a prefix is set, false otherwise.
	 * @since      1.0.0
	 */
	public function hasPrefix();
	
	/**
	 * Set the postfix.
	 * @param      string $value The postfix.
	 * @since      1.0.0
	 */
	public function setPostfix($value);
	
	/**
	 * Retrieve the postfix.
	 * @return     string The postfix.
	 * @since      1.0.0
	 */
	public function getPostfix();
	
	/**
	 * Check if a postfix is set.
	 * @return     bool True, if a postfix is set, false otherwise.
	 * @since      1.0.0
	 */
	public function hasPostfix();
	
	/**
	 * Set whether or not the value needs to be encoded.
	 * @param      bool $needsEncoding True, if the postfix needs encoding, false otherwise.
	 * @since      1.0.0
	 */
	public function setValueNeedsEncoding($needsEncoding);
	
	/**
	 * Retrieve whether or not the value needs to be encoded.
	 * @return     bool True, if the value needs encoding, false otherwise.
	 * @since      1.0.0
	 */
	public function getValueNeedsEncoding();
	
	/**
	 * Set whether or not the prefix needs to be encoded.
	 * @param      bool $needsEncoding True, if the prefix needs encoding, false otherwise.
	 * @since      1.0.0
	 */
	public function setPrefixNeedsEncoding($needsEncoding);
	
	/**
	 * Retrieve whether or not the prefix needs to be encoded.
	 * @return     bool True, if the prefix needs encoding, false otherwise.
	 * @since      1.0.0
	 */
	public function getPrefixNeedsEncoding();
	
	/**
	 * Set whether or not the postfix needs to be encoded.
	 * @param      bool $needsEncoding True, if the postfix needs encoding, false otherwise.
	 * @since      1.0.0
	 */
	public function setPostfixNeedsEncoding($needsEncoding);
	
	/**
	 * Retrieve whether or not the postfix needs to be encoded.
	 * @return     bool True, if the postfix needs encoding, false otherwise.
	 * @since      1.0.0
	 */
	public function getPostfixNeedsEncoding();
	
	/**
	 * Check if this routing value is equal to the given parameter.
	 * @param      mixed $other The value to compare $this against.
	 * @return     bool Whether the value matches $this.
	 * @since      1.0.0
	 */
	public function equals($other);
	
	/**
	 * Return the encoded value (without pre- or postfix) for BC.
	 * @return     string The encoded value.
	 * @since      1.0.0
	 */
	public function __toString();
}

?>