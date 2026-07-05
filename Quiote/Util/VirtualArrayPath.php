<?php
namespace Quiote\Util;
/**
 * Path implements handling of virtual paths
 * This class does not implement real filesystem path handling, but uses virtual
 * paths. It is primary used in the validation system for handling arrays of
 * input. 
 * @since      1.0.0
 * @version    1.0.0
 */
class VirtualArrayPath implements \Stringable
{
	/**
	 * @var        bool Is path absolute?
	 */
	protected $absolute = false;
	/**
	 * @var        array<int, int|string> Array components.
	 */
	protected $parts = [];
	
	/**
	 * constructor
	 * @param      ?string $path The path to be handled by the object
	 * @since      1.0.0
	 */
	public function __construct(?string $path)
	{
		if ($path == null) $path = "";
		if(strlen($path) == 0) {
			$this->absolute = true;
			return;
		}
		
		$parts = ArrayPathDefinition::getPartsFromPath($path);
		
		$this->absolute = $parts['absolute'];
		$this->parts = $parts['parts'];
	}
	
	/**
	 * Returns whether the path is absolute.
	 * @return     bool True if the path is absolute.
	 * @since      1.0.0
	 */
	public function isAbsolute()
	{
		return $this->absolute;
	}
	
	/**
	 * Returns the string representation of the path.
	 * @return     string The path as string.
	 * @since      1.0.0
	 */
	public function __toString(): string
	{
		$parts = $this->parts;
		if(count($parts) == 0) {
			return '';
		}

		$name = '';
		if($this->absolute) {
			$name = $parts[0];
			$parts = array_slice($parts, 1);
		}
		$path = '';
		if(count($parts)) {
			$path = sprintf('[%s]', implode('][', $parts));
		}

		return $name . $path;
	}
	
	/**
	 * Returns the number of components the path has.
	 * @return     int The number of components.
	 * @since      1.0.0
	 */
	public function length()
	{
		return count($this->parts);
	}
	
	/**
	 * Returns the given component of the path.
	 * @param      int $position Position of the component.
	 * @return     int|string|null The component at the given position, or null if out of range.
	 * @since      1.0.0
	 */
	public function get($position)
	{
		if($position < 0 || $position >= $this->length()) {
			return null;
		}

		$part = $this->parts[$position];

		if((string)(int)$part == $part) {
			$part = (int)$part;
		}

		return $part;
	}

	/**
	 * Returns the root component of the path.
	 * @param      bool $addBracketsWhenRelative Whether brackets should be added around the component if
	 *                  this path is not absolute.
	 * @return     int|string|null The root component, or null if the path is empty.
	 * @since      1.0.0
	 */
	public function left($addBracketsWhenRelative = false)
	{
		if(!$this->length()) {
			return null;
		}

		$part = $this->parts[0];

		if((string)(int)$part == $part) {
			$part = (int)$part;
		}

		if(!$this->absolute && $addBracketsWhenRelative) {
			$part = sprintf('[%s]', $part);
		}

		return $part;
	}
	
	/**
	 * Returns the last component of the path and deletes it from the path.
	 * @return     int|string|null The last component, or null if the path is empty.
	 * @since      1.0.0
	 */
	public function pop()
	{
		if(!$this->length()) {
			return null;
		}

		$part = array_pop($this->parts);

		if((string)(int)$part == $part) {
			return (int)$part;
		} else {
			return $part;
		}
	}
	
	/**
	 * Appends one or more components to the path.
	 * @param      string $path The components to be added.
	 * @return     void
	 * @since      1.0.0
	 */
	public function push($path)
	{
		$parts = ArrayPathDefinition::getPartsFromPath($path);
		$this->parts = array_merge($this->parts, $parts['parts']);
	}

	/**
	 * Clones this path, appends one or more components to it and returns it.
	 * @param      string $path the components to be added.
	 * @return     static
	 * @since      1.0.0
	 */
	public function pushRetNew($path)
	{
		$new = clone $this;
		$new->push($path);
		return $new;
	}
	
	/**
	 * Returns the root component of the path and deletes it from the path.
	 * @param      bool $addBracketsWhenRelative Whether brackets should be added around the component if
	 *                  this path is not absolute.
	 * @return     int|string|null The root component, or null if the path is empty.
	 * @since      1.0.0
	 */
	public function shift($addBracketsWhenRelative = false)
	{
		if(!$this->length()) {
			return null;
		}
		
		$ret = $this->left($addBracketsWhenRelative);

		array_shift($this->parts);

		if($this->absolute) {
			$this->absolute = false;
		}

		return $ret;
	}

	/**
	 * Prepends one or more components to the path.
	 * @param      string $path The components to be prepended.
	 * @return     void
	 * @since      1.0.0
	 */
	public function unshift($path)
	{
		$parts = ArrayPathDefinition::getPartsFromPath($path);
		$this->parts = array_merge($parts['parts'], $this->parts);
	}

	/**
	 * Checks if a value exists  at the path of this instance in the given array.
	 * @param      array<mixed> $array The array to check.
	 * @return     bool
	 * @since      1.0.0
	 */
	public function hasValue(array &$array)
	{
		return ArrayPathDefinition::hasValue($this->parts, $array);
	}

	/**
	 * Returns the value at the path of this instance in the given array.
	 * @param array<mixed> $array The array to get the data from.
	 * @param mixed $default The default value to be used if the path doesn't exist.
	 * @return mixed The value at the path.
	 * @since 1.0.0
	 */
	public function &getValue(array &$array, $default = null)
	{
		return ArrayPathDefinition::getValue($this->parts, $array, $default);
	}

	/**
	 * Sets the value at the path of this instance in the given array.
	 * @param array<mixed> $array The array to set the data in.
	 * @param mixed $value The value to be set.
	 * @return void
	 * @since 1.0.0
	 */
	public function setValue(array &$array, $value)
	{
		ArrayPathDefinition::setValue($this->parts, $array, $value);
	}

	/**
	 * Returns the value at the given child path of this instance in the given 
	 * array.
	 * @param string $path The child path appended to the path in this instance.
	 * @param array<mixed> $array The array to get the data from.
	 * @param mixed $default The default value to be used if the path doesn't exist.
	 * @return mixed The value at the path.
	 * @since 1.0.0
	 */
	public function &getValueByChildPath($path, array &$array, $default = null)
	{
		$p = $this->pushRetNew($path);

		return $p->getValue($array, $default);
	}

	/**
	 * Sets the value at the given child path of this instance in the given array.
	 * @param      string $path The child path appended to the path in this instance.
	 * @param      array<mixed> $array The array to set the data in.
	 * @param      mixed $value The value to be set.
	 * @return     void
	 * @since      1.0.0
	 */
	public function setValueByChildPath($path, array &$array, $value)
	{
		$p = $this->pushRetNew($path);

		$p->setValue($array, $value);
	}

	/**
	 * Checks if a value at the given child path exists in the given array.
	 * @param      string $path The child path appended to the path in this instance.
	 * @param      array<mixed> $array The array to check.
	 * @return     bool
	 * @since      1.0.0
	 */
	public function hasValueByChildPath($path, array &$array)
	{
		$p = $this->pushRetNew($path);

		return $p->hasValue($array);
	}

	/**
	 * Returns the components of this path.
	 * @return     array<int, int|string> The components
	 * @since      1.0.0
	 */
	public function getParts()
	{
		return $this->parts;
	}
}
?>