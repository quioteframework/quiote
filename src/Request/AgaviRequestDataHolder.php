<?php

// +---------------------------------------------------------------------------+
// | This file is part of the Agavi package.                                   |
// | Copyright (c) 2005-2011 the Agavi Project.                                |
// |                                                                           |
// | For the full copyright and license information, please view the LICENSE   |
// | file that was distributed with this source code. You can also view the    |
// | LICENSE file online at http://www.agavi.org/LICENSE.txt                   |
// |   vi: set noexpandtab:                                                    |
// |   Local Variables:                                                        |
// |   indent-tabs-mode: t                                                     |
// |   End:                                                                    |
// +---------------------------------------------------------------------------+
namespace Agavi\Request;

use Agavi\Util\AgaviInflector;
use Agavi\Util\AgaviParameterHolder;
use InvalidArgumentException;
use Symfony\Contracts\Service\ResetInterface;

/**
 * AgaviRequestDataHolder provides methods for retrieving client request 
 * information parameters.
 *
 * @package    agavi
 * @subpackage request
 *
 * @author     Dominik del Bondio <ddb@bitxtender.com>
 * @copyright  Authors
 * @copyright  The Agavi Project
 *
 * @since      0.11.0
 *
 * @version    $Id$
 */
class AgaviRequestDataHolder extends AgaviParameterHolder implements AgaviIParametersRequestDataHolder, ResetInterface
{
	/**
	 * When true, only parameters whitelisted in $validatedKeys may be accessed.
	 */
	protected bool $enforceValidated = false;

	/**
	 * Whitelisted validated parameter names.
	 */
	protected array $validatedKeys = [];
	/**
	 * @constant   Constant for source name of parameters.
	 */
	const SOURCE_PARAMETERS = 'parameters';

	/*
	 * @var        array An array of source names and references to their data
	 *                   containers. Unset again after construction is complete.
	 */
	private $sources = [];

	/*
	 * @var        array An array of plural source names and their singular forms.
	 */
	private $sourceNames = [];

	/**
	 * Merge in parameters from another request data holder.
	 *
	 * @param      AgaviRequestDataHolder The other request data holder.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function mergeParameters(AgaviRequestDataHolder $other)
	{
		$this->setParameters($other->getParameters());
	}

	/**
	 * Checks if there is a value of a parameter is empty.
	 *
	 * @param      string The field name.
	 *
	 * @return     bool The result.
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @since      0.11.0
	 */
	public function isParameterValueEmpty($field)
	{
		return !$this->hasParameter($field);
	}
	
	/**
	 * Deletes all fields in a given source.
	 *
	 * @param      string The name of the source to operate on.
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @since      0.11.0
	 */
	public function clear($source)
	{
		if(isset($this->$source)) {
			$funcname = 'clear' . $source;
			$this->$funcname();
		} else {
			throw new \InvalidArgumentException('Unknown source ' . $source . ' specified');
		}
	}

	/**
	 * Deletes all fields in all sources.
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @since      0.11.0
	 */
	public function clearAll()
	{
		foreach($this->sourceNames as $sourceName => $source) {
			$funcname = 'clear' . $sourceName;
			$this->$funcname();
		}
	}

	/**
	 * Retrieves a field from one of the stored data types.
	 *
	 * @param      string The name of the source to operate on.
	 * @param      string A field name.
	 * @param      mixed  A default value.
	 *
	 * @return     mixed The field value.
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function &get($source, $field, $default = null)
	{
		if(isset($this->$source)) {
			$funcname = 'get' . $this->sourceNames[$source];
			return $this->$funcname($field, $default);
		} else {
			throw new \InvalidArgumentException('Unknown source ' . $source . 'specified');
		}
	}

	/**
	 * Retrieves all fields of a stored data types.
	 *
	 * @param      string The name of the source to operate on.
	 *
	 * @return     mixed The values.
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function &getAll($source)
	{
		if(isset($this->$source)) {
			$funcname = 'get' . $source;
			return $this->$funcname();
		} else {
			throw new \InvalidArgumentException('Unknown source ' . $source . 'specified');
		}
	}

	/**
	 * Checks if a field exists.
	 *
	 * @param      string The name of the source to operate on.
	 * @param      string A field name.
	 *
	 * @return     bool The result.
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function has($source, $field)
	{
		if(isset($this->$source)) {
			$funcname = 'has' . $this->sourceNames[$source];
			return $this->$funcname($field);
		} else {
			throw new \InvalidArgumentException('Unknown source ' . $source . 'specified');
		}
	}

	/**
	 * Checks if a field has no value (In web context this would only return true
	 * when the strings length is 0 or the field is not set.
	 *
	 * @param      string The name of the source to operate on.
	 * @param      string A field name.
	 *
	 * @return     bool The result.
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function isValueEmpty($source, $field)
	{
		if(isset($this->$source)) {
			$funcname = 'is' . $this->sourceNames[$source] . 'ValueEmpty';
			return $this->$funcname($field);
		} else {
			throw new InvalidArgumentException('Unknown source ' . $source . 'specified');
		}
	}

	/**
	 * Removes a field.
	 *
	 * @param      string The name of the source to operate on.
	 * @param      string A field name.
	 *
	 * @return     mixed The removed value.
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function &remove($source, $field)
	{
		if(isset($this->$source)) {
			$funcname = 'remove' . $this->sourceNames[$source];
			return $this->$funcname($field);
		} else {
			throw new InvalidArgumentException('Unknown source ' . $source . 'specified');
		}
	}

	/**
	 * Sets a field.
	 *
	 * @param      string The name of the source to operate on.
	 * @param      string A field name.
	 * @param      mixed  A value.
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function set($source, $field, $value)
	{
		if(isset($this->$source)) {
			$funcname = 'set' . $this->sourceNames[$source];
			$this->$funcname($field, $value);
		} else {
			throw new InvalidArgumentException('Unknown source ' . $source . 'specified');
		}
	}
	
	/**
	 * Register a source with the holder. Must be called in constructors, and
	 * prior to calling the parent ctor.
	 *
	 * @param      string The source name, typically passed using a constant.
	 * @param      array  The variable that will hold the data for the source.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	final protected function registerSource($name, array &$holder)
	{
		$this->sources[$name] =& $holder;
		$this->sourceNames[$name] = AgaviInflector::singularize($name);
	}
	
	/**
	 * Merge in another request data holder.
	 *
	 * This method calls mergeSourcename for each source.
	 *
	 * @param      AgaviRequestDataHolder The other request data holder.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function merge(AgaviRequestDataHolder $other)
	{
		foreach(array_keys($this->sourceNames) as $source) {
			$fn = 'merge' . $source; // plural form!
			$this->$fn($other);
		}
	}

	/**
	 * Activate strict validated-parameter enforcement with the given whitelist.
	 * Subsequent attempts to access a non-validated parameter via getParameter()/hasParameter()
	 * will return default (for has: false) and log an error in debug mode, or throw if hard enforcement enabled.
	 *
	 * @param array $keys Allowed parameter names (validated)
	 * @param bool $hardThrow If true, throw an exception on illegal access; otherwise silently deny.
	 */
	public function enforceValidatedParameters(array $keys, bool $hardThrow = true): void
	{
		$this->enforceValidated = true;
		$this->validatedKeys = array_fill_keys($keys, true);
		$this->hardThrowOnInvalid = $hardThrow;
	}

	/** @var bool */
	protected bool $hardThrowOnInvalid = true;

	private function handleUnvalidatedAccess(string $name)
	{
		if($this->hardThrowOnInvalid) {
			throw new \Agavi\Exception\AgaviUnvalidatedParameterAccessException('Access to unvalidated parameter "' . $name . '" denied under strict validation.');
		}
		if(getenv('AGAVI_VALIDATION_DEBUG')) {
			@trigger_error('Unvalidated parameter access denied: ' . $name, E_USER_WARNING);
		}
	}

	public function &getParameter($name, $default = null)
	{
		if($this->enforceValidated) {
			if(!isset($this->validatedKeys[$name])) {
				$this->handleUnvalidatedAccess($name);
				return $default; // not validated
			}
		}
		return parent::getParameter($name, $default);
	}

	public function hasParameter($name)
	{
		if($this->enforceValidated && !isset($this->validatedKeys[$name])) {
			return false;
		}
		return parent::hasParameter($name);
	}
	
	/**
	 * Returns all the registered source names.
	 *
	 * @return     array A list of source names.
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @since      0.11.0
	 */
	public final function getSourceNames()
	{
		return array_keys($this->sourceNames);
	}

	/**
	 * Constructor
	 *
	 * @param      array An associative array of request data source names and
	 *                   data arrays.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function __construct(array $data = [])
	{
		$this->registerSource(self::SOURCE_PARAMETERS, $this->parameters);
		
		foreach($this->sources as $name => &$container) {
			if(isset($data[$name]) && is_array($data[$name])) {
				$container = $data[$name];
			} else {
				$container = [];
			}
		}
		
		// unset it to clean up references that otherwise would mess up cloning
		unset($this->sources);
	}

	/**
	 * Reset request data holder state for FrankenPHP worker compatibility.
	 * Clears request-specific data that could leak between requests.
	 *
	 * @author     Generated for FrankenPHP worker compatibility
	 * @since      1.1.0
	 */
	public function reset(): void
	{
		$this->sources = [];
		$this->sourceNames = [];
		$this->enforceValidated = false;
		$this->validatedKeys = [];
		$this->hardThrowOnInvalid = true;
		
		// Reset parent parameter holder state
		parent::clearParameters();
	}
}

?>