<?php
namespace Quiote\Util;

use InvalidArgumentException;
use Symfony\Contracts\Service\ResetInterface;

/**
 * ParameterHolder provides a base class for managing parameters.
 * @since      1.0.0
 * @version    1.0.0
 */
class ParameterHolder implements ResetInterface
{
	/**
	 * @var        array An array of parameters
	 */
	protected $parameters = [];

	/**
	 * Constructor. Accepts an array of initial parameters as an argument.
	 * @param      array $parameters An array of parameters to be set right away.
	 * @since      1.0.0
	 */
	public function __construct(array $parameters = [])
	{
		$this->parameters = $parameters;
	}

	/**
	 * Clear all parameters associated with this request.
	 * @since      1.0.0
	 */
	public function clearParameters()
	{
		$this->parameters = [];
	}

	/**
	 * Retrieve a parameter.
	 * @param      string $name A parameter name.
	 * @param      mixed  $default A default parameter value.
	 * @return     mixed A parameter value, if the parameter exists, otherwise
	 *                   null.
	 * @since      1.0.0
	 */
	public function &getParameter($name, $default = null)
	{
		if(isset($this->parameters[$name]) || array_key_exists($name, $this->parameters)) {
			return $this->parameters[$name];
		}
		try {
			return ArrayPathDefinition::getValue($name, $this->parameters, $default);
		} catch(InvalidArgumentException) {
			return $default;
		}
	}

	/**
	 * Retrieve an array of parameter names.
	 * @return     array An indexed array of parameter names.
	 * @since      1.0.0
	 */
	public function getParameterNames()
	{
		return array_keys($this->parameters);
	}

	/**
	 * Retrieve an array of flattened parameter names. This means when a parameter
	 * is an array you wont get the name of the parameter in the result but 
	 * instead all child keys appended to the name (like foo[0],foo[1][0], ...)
	 * @return     array An indexed array of parameter names.
	 * @since      1.0.0
	 */
	public function getFlatParameterNames()
	{
		return ArrayPathDefinition::getFlatKeyNames($this->parameters);
	}

	/**
	 * Retrieve an array of parameters.
	 * @return     array An associative array of parameters.
	 * @since      1.0.0
	 */
	public function &getParameters()
	{
		return $this->parameters;
	}

	/**
	 * Indicates whether or not a parameter exists.
	 * @param      string $name A parameter name.
	 * @return     bool true, if the parameter exists, otherwise false.
	 * @since      1.0.0
	 */
	public function hasParameter($name)
	{
		if(isset($this->parameters[$name]) || array_key_exists($name, $this->parameters)) {
			return true;
		}
		try {
			return ArrayPathDefinition::hasValue($name, $this->parameters);
		} catch(InvalidArgumentException) {
			return false;
		}
	}

	/**
	 * Remove a parameter.
	 * @param      string $name A parameter name.
	 * @return     string A parameter value, if the parameter was removed,
	 *                    otherwise null.
	 * @since      1.0.0
	 */
	public function &removeParameter($name)
	{
		if(isset($this->parameters[$name]) || array_key_exists($name, $this->parameters)) {
			$retval =& $this->parameters[$name];
			unset($this->parameters[$name]);
			return $retval;
		}
		
		$retval = null;
		try {
			$retval =& ArrayPathDefinition::unsetValue($name, $this->parameters);
		} catch(InvalidArgumentException) {
		}
		return $retval;
	}

	/**
	 * Set a parameter.
	 * If a parameter with the name already exists the value will be overridden.
	 * @param      string $name A parameter name.
	 * @param      mixed  $value A parameter value.
	 * @since      1.0.0
	 */
	public function setParameter($name, $value)
	{
		$this->parameters[$name] = $value;
	}

	/**
	 * Append a parameter.
	 * If this parameter is already set, convert it to an array and append the
	 * new value.  If not, set the new value like normal.
	 * @param      string $name A parameter name.
	 * @param      mixed  $value A parameter value.
	 * @since      1.0.0
	 */
	public function appendParameter($name, $value)
	{
		if(!isset($this->parameters[$name]) || !is_array($this->parameters[$name])) {
			settype($this->parameters[$name], 'array');
		}
		$this->parameters[$name][] = $value;
	}

	/**
	 * Set a parameter by reference.
	 * If a parameter with the name already exists the value will be
	 * overridden.
	 * @param      string $name A parameter name.
	 * @param      mixed  $value A reference to a parameter value.
	 * @since      1.0.0
	 */
	public function setParameterByRef($name, &$value)
	{
		$this->parameters[$name] =& $value;
	}

	/**
	 * Append a parameter by reference.
	 * If this parameter is already set, convert it to an array and append the
	 * reference to the new value.  If not, set the new value like normal.
	 * @param      string $name A parameter name.
	 * @param      mixed  $value A reference to a parameter value.
	 * @since      1.0.0
	 */
	public function appendParameterByRef($name, &$value)
	{
		if(!isset($this->parameters[$name]) || !is_array($this->parameters[$name])) {
			settype($this->parameters[$name], 'array');
		}
		$this->parameters[$name][] =& $value;
	}

	/**
	 * Set an array of parameters.
	 * If an existing parameter name matches any of the keys in the supplied
	 * array, the associated value will be overridden.
	 * @param      array $parameters An associative array of parameters and their associated
	 *                   values.
	 * @since      1.0.0
	 */
	public function setParameters(array $parameters)
	{
		// array_merge would reindex numeric keys, so we use the + operator
		// mind the operand order: keys that exist in the left one aren't overridden
		$this->parameters = $parameters + $this->parameters;
	}

	/**
	 * Set an array of parameters by reference.
	 * If an existing parameter name matches any of the keys in the supplied
	 * array, the associated value will be overridden.
	 * @param      array $parameters An associative array of parameters and references to their
	 *                   associated values.
	 * @since      1.0.0
	 */
	public function setParametersByRef(array &$parameters)
	{
		foreach($parameters as $key => &$value) {
			$this->parameters[$key] =& $value;
		}
	}

	public function reset() : void
	{
		$this->clearParameters();
	}

}

?>