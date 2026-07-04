<?php
namespace Quiote\Util;

use InvalidArgumentException;

/**
 * Path implements handling of virtual paths
 * This class does not implement real filesystem path handling, but uses virtual
 * paths. It is primary used in the validation system for handling arrays of
 * input. 
 * @since      1.0.0
 * @version    1.0.0
 */
final class ArrayPathDefinition
{
	/**
	 * constructor
	 * @since      1.0.0
	 */
	private function __construct()
	{
	}

	/**
	 * Converts the given argument to an array of parts for use in the path getter/setters
	 * @param      array|string $partsArrayOrPathString The path string or an array containing the path
	 *                          divided into its individual parts.
	 * @return     array        The array of parts.
	 * @since      1.0.0
	 */
	protected static function preparePartsArray($partsArrayOrPathString)
	{
		if(is_array($partsArrayOrPathString)) {
			return $partsArrayOrPathString;
		} else {
			$partInfo = self::getPartsFromPath($partsArrayOrPathString);
			$parts = $partInfo['parts'];
			if(!$partInfo['absolute']) {
				// the value wasn't absolute, so an empty string is used for the first part
				array_unshift($parts, '');
			}
			return $parts;
		}
		
	}
	
	/**
	 * Unsets a value at the given path.
	 * @param      array|string $partsArrayOrPathString The path string or an array containing the path
	 *                          divided into its individual parts.
	 * @param      array $array The array we should operate on.
	 * @return     mixed The previously stored value.
	 * @since      1.0.0
	 */
	public static function &unsetValue($partsArrayOrPathString, array &$array)
	{
		$parts = self::preparePartsArray($partsArrayOrPathString);
		
		$a =& $array;

		$c = count($parts);
		for($i = 0; $i < $c; ++$i) {
			$part = $parts[$i];
			$last = ($i+1 == $c);
			if($part !== null) {
				if(is_array($a) && is_numeric($part) && !str_contains($part, '.') && !str_contains($part, ',') && (isset($a[(int)$part]) || array_key_exists((int)$part, $a))) {
					$part = (int)$part;
				}
				if(is_array($a) && (isset($a[$part]) || array_key_exists($part, $a))) {
					if($last) {
						$oldValue =& $a[$part];
						unset($a[$part]);
						return $oldValue;
					} else {
						$a =& $a[$part];
					}
				} else {
					$retval = null;
					return $retval;
				}
			}
		}
		$retval = null;
		return $retval;
	}

	/**
	 * Checks whether the array has a value at the given path.
	 * @param      array|string $partsArrayOrPathString The path string or an array containing the path
	 *                          divided into its individual parts.
	 * @param      array $array The array we should operate on.
	 * @return     bool Whether the path exists in this array.
	 * @since      1.0.0
	 */
	public static function hasValue($partsArrayOrPathString, array &$array)
	{
		$parts = self::preparePartsArray($partsArrayOrPathString);
		
		$a = $array;

		foreach($parts as $part) {
			if($part !== null) {
				if(is_array($a) && is_numeric($part) && !str_contains($part, '.') && !str_contains($part, ',') && (isset($a[(int)$part]) || array_key_exists((int)$part, $a))) {
					$part = (int)$part;
				}
				if(is_array($a) && (isset($a[$part]) || array_key_exists($part, $a))) {
					$a = $a[$part];
				} else {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Returns the value at the given path.
	 * @param      array|string $partsArrayOrPathString The path string or an array containing the path
	 *                          divided into its individual parts.
	 * @param      array $array The array we should operate on.
	 * @param      mixed $default A default value if the path doesn't exist in the array.
	 * @return     mixed The value stored at the given path.
	 * @since      1.0.0
	 */
	public static function &getValue($partsArrayOrPathString, array &$array, $default = null)
	{
		$parts = self::preparePartsArray($partsArrayOrPathString);
		
		$a = &$array;

		foreach($parts as $part) {
			if($part !== null) {
				if(is_array($a) && is_numeric($part) && !str_contains($part, '.') && !str_contains($part, ',') && (isset($a[(int)$part]) || array_key_exists((int)$part, $a))) {
					$part = (int)$part;
				}
				if(is_array($a) && (isset($a[$part]) || array_key_exists($part, $a))) {
					$a = &$a[$part];
				} else {
					//throw new \Exception('The part: ' . $part . ' does not exist in the given array');
					return $default;
				}
			}
		}

		return $a;
	}

	/**
	 * Sets the value at the given path.
	 * @param      array|string $partsArrayOrPathString The path string or an array containing the path
	 *                          divided into its individual parts.
	 * @param      array $array The array we should operate on.
	 * @param      mixed $value The value.
	 * @since      1.0.0
	 */
	public static function setValue($partsArrayOrPathString, array &$array, $value)
	{
		$parts = self::preparePartsArray($partsArrayOrPathString);
		
		$a = &$array;

		foreach($parts as $part) {
			if($part !== null) {
				if(is_numeric($part) && !str_contains($part, '.') && !str_contains($part, ',') && (isset($a[(int)$part]) || array_key_exists((int)$part, $a))) {
					$part = (int)$part;
				}
				if(!isset($a[$part]) || !is_array($a[$part]) || !(isset($a[$part]) || array_key_exists($part, $a))) {
					$a[$part] = [];
				}
				$a = &$a[$part];
			}
		}

		$a = $value;
	}

	/**
	 * Returns an array with the single parts of the given path.
	 * @param      string $path The path.
	 * @return     array The parts of the given path.
	 * @since      1.0.0
	 */
	public static function getPartsFromPath($path)
	{
		$pathStr = (string) $path;
		if(strlen($pathStr) == 0) {
			return ['parts' => [], 'absolute' => true];
		}

		$parts = [];
		$absolute = ($pathStr[0] != '[');
		if(($pos = strpos($pathStr, '[')) === false) {
			if(str_contains($pathStr, ']')) {
				throw new InvalidArgumentException('Invalid "]" without opening "[" found');
			}
			$parts[] = $path;
		} else {
			$state = 0;
			$cur = '';
			foreach(str_split($pathStr) as $c) {
				// this is the fastest way to loop over an string
				switch($state) {
					// the order is significant for performance
					case 2:
						// match all characters between []
						if($c == ']') {
							$parts[] = $cur;
							$cur = '';
							$state = 1;
						} elseif($c == '[') {
							throw new InvalidArgumentException('Invalid "[[" found');
						} else {
							$cur .= $c;
						}
						
						break;

					case 0:
						// match everything to the first '['
						if($c != '[') {
							$cur .= $c;
						} else {
							if($cur !== '') {
								$parts[] = $cur;
								$cur = '';
							}
							$state = 2;
						}
						break;

					case 1:
						// match exactly '['
						if($c == '[') {
							$state = 2;
						} else {
							throw new InvalidArgumentException('Invalid character after "]" found');
						}
						break;

				}
			}
			if($state == 0) {
				$parts[] = $cur;
			} elseif($state == 2) {
				throw new InvalidArgumentException('Missing "]" after opening "["');
			}
		}

		return ['parts' => $parts, 'absolute' => $absolute];
	}


	/**
	 * Returns the flat key names of an array.
	 * This method calls itself recursively to flatten the keys.
	 * @param      array $array The array which keys should be returned.
	 * @param      string $prefix The prefix for the name (only for internal use).
	 * @return     array The flattened keys.
	 * @since      1.0.0
	 */
	public static function getFlatKeyNames(array $array, $prefix = null)
	{
		$names = [];
		foreach($array as $key => $value) {
			if($prefix === null) {
				// create the top node when no prefix was given
				if(strlen((string) $key) == 0) {
					// when an empty key was used at top level, create a "relative" path, so the empty string doesn't get lost
					$name = '[' . $key . ']';
				} else {
					$name = $key;
				}
			} else {
				$name = $prefix . '[' . $key . ']';
			}

			if(is_array($value)) {
				$names = array_merge($names, ArrayPathDefinition::getFlatKeyNames($value, $name));
			} else {
				$names[] = $name;
			}
		}
		return $names;
	}
	
	/**
	 * Returns the flattened version of an array. So the returned array 
	 * will be one dimensional with the flattened key names as keys
	 * and their values from the original array as values.
	 * This method calls itself recursively to flatten the array.
	 * @param      array $array The array which should be flattened.
	 * @param      string $prefix The prefix for the key names (only for internal use).
	 * @return     array The flattened array.
	 * @since      1.0.0
	 */
	public static function flatten($array, $prefix = null)
	{
		$flatArray = [];
		foreach($array as $key => $value) {
			if($prefix === null) {
				// create the top node when no prefix was given
				if(strlen((string) $key) == 0) {
					// when an empty key was used at top level, create a "relative" path, so the empty string doesn't get lost
					$name = '[' . $key . ']';
				} else {
					$name = $key;
				}
			} else {
				$name = $prefix . '[' . $key . ']';
			}
			
			if(is_array($value)) {
				$flatArray += ArrayPathDefinition::flatten($value, $name);
			} else {
				$flatArray[$name] = $value;
			}
		}
		return $flatArray;
	}
}
?>