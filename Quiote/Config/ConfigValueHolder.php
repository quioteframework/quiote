<?php
namespace Quiote\Config;

use Quiote\Util\Inflector;
use AllowDynamicProperties;

/**
 * ConfigValueHolder is the storage class for the XmlConfigHandler
 * @since      1.0.0
 * @deprecated Not used anymore by XML config handlers, to be removed in Quiote 1.1
 * @version    1.0.0
 * @property-read ?ConfigValueHolder $configurations Dynamically added child node, if it exists.
 * @property-read ?ConfigValueHolder $parameters Dynamically added child node, if it exists.
 */
#[AllowDynamicProperties]
class ConfigValueHolder implements \ArrayAccess, \IteratorAggregate, \Stringable
{
	/**
	 * @var        string The name of this value.
	 */
	protected $_name = '';
	/**
	 * @var        array The attributes of this value.
	 */
	protected $_attributes = [];
	/**
	 * @var        array The child nodes of this value.
	 */
	protected $_childs = [];
	/**
	 * @var        string The value.
	 */
	protected $_value = null;


	/**
	 * Sets the name of this value.
	 * @param      string $name The name.
	 * @since      1.0.0
	 */
	public function setName($name)
	{
		$this->_name = $name;
	}

	/**
	 * Returns the name of this value.
	 * @return     string The name.
	 * @since      1.0.0
	 */
	public function getName()
	{
		return $this->_name;
	}

	/**
	 * isset() overload.
	 * @param      string $name Name of the child.
	 * @return     bool Whether or not that child exists.
	 * @since      1.0.0
	 */
	public function __isset($name)
	{
		return $this->hasChildren($name);
	}

	/**
	 * Magic getter overload.
	 * @param      string $name Name of the child.
	 * @return     ?ConfigValueHolder The child, if it exists.
	 * @since      1.0.0
	 */
	public function __get($name)
	{
		if(isset($this->_childs[$name])) {
			return $this->_childs[$name];
		} else {
			$tagName = $name;
			$tagNameStart = '';
			if(($lastUScore = strrpos((string) $tagName, '_')) !== false) {
				$lastUScore++;
				$tagNameStart = substr((string) $tagName, 0, $lastUScore);
				$tagName = substr((string) $tagName, $lastUScore);
			}

			// check if the requested node was specified using the plural version
			// and create a "virtual" node which reflects the non existent plural node
			$singularName = $tagNameStart . Inflector::singularize($tagName);
			if($this->hasChildren($singularName)) {

				$vh = new ConfigValueHolder();
				$vh->setName($name);

				foreach($this->_childs as $child) {
					if($child->getName() == $singularName) {
						$vh->addChildren($singularName, $child);
					}
				}

				return $vh;
			} else {
				//throw new \Exception('Node with the name ' . $name . ' does not exist ('.$this->getName().', '.implode(', ', array_keys($this->_childs)).')');
				return null;
			}
		}
	}

	/**
	 * Adds a named children to this value. If a children with the same name
	 * already exists the given value will be appended to the children.
	 * @param      string $name The name of the child.
	 * @param      ConfigValueHolder $children The child value.
	 * @since      1.0.0
	 */
	public function addChildren($name, $children)
	{
		if(!$this->hasChildren($name)) {
			$this->$name = $children;
			$this->_childs[$name] = $children;
		} else {
			$this->appendChildren($children);
		}
	}

	/**
	 * Adds a unnamed children to this value.
	 * @param      ConfigValueHolder $children The child value.
	 * @since      1.0.0
	 */
	public function appendChildren($children)
	{
		$this->_childs[] = $children;
	}

	/**
	 * Checks whether the value has children at all (no params) or whether a
	 * child with the given name exists.
	 * @param      ?string $child The name of the child.
	 * @return     bool True if children exist, false if not.
	 * @since      1.0.0
	 */
	public function hasChildren($child = null)
	{
		if($child === null) {
			return count($this->_childs) > 0;
		}

		if(isset($this->_childs[$child])) {
			return true;
		} else {
			$tagName = $child;
			$tagNameStart = '';
			if(($lastUScore = strrpos($tagName, '_')) !== false) {
				$lastUScore++;
				$tagNameStart = substr($tagName, 0, $lastUScore);
				$tagName = substr($tagName, $lastUScore);
			}

			$singularName = $tagNameStart . Inflector::singularize($tagName);
			return isset($this->_childs[$singularName]);
		}
	}

	/**
	 * Returns the children of this value.
	 * @param      ?string $nodename Return only the childs matching this node (tag) name.
	 * @return     array An array with the childs of this value.
	 * @since      1.0.0
	 */
	public function getChildren($nodename = null)
	{
		if($nodename === null) {
			return $this->_childs;
		} else {
			$childs = [];
			foreach($this->_childs as $child) {
				if($child->getName() == $nodename) {
					$childs[] = $child;
				}
			}

			return $childs;
		}
	}

	/**
	 * Set an attribute.
	 * If an attribute with the name already exists the value will be
	 * overridden.
	 * @param      string $name An attribute name.
	 * @param      mixed  $value An attribute value.
	 * @since      1.0.0
	 */
	public function setAttribute($name, $value)
	{
		$this->_attributes[$name] = $value;
	}

	/**
	 * Indicates whether or not an attribute exists.
	 * @param      string $name An attribute name.
	 * @return     bool true, if the attribute exists, otherwise false.
	 * @since      1.0.0
	 */
	public function hasAttribute($name)
	{
		return isset($this->_attributes[$name]);
	}

	/**
	 * Retrieve an attribute.
	 * @param      string $name An attribute name.
	 * @param      mixed  $default A default attribute value.
	 * @return     mixed An attribute value, if the attribute exists, otherwise
	 *                   null or the given default.
	 * @since      1.0.0
	 */
	public function getAttribute($name, $default = null)
	{
		return $this->_attributes[$name] ?? $default;
	}

	/**
	 * Retrieve all attributes.
	 * @return     array An associative array of attributes.
	 * @since      1.0.0
	 */
	public function getAttributes()
	{
		return $this->_attributes;
	}

	/**
	 * Set the value of this value node.
	 * @param      string $value A value.
	 * @since      1.0.0
	 */
	public function setValue($value)
	{
		$this->_value = $value;
	}

	/**
	 * Retrieves the value of this value node.
	 * @return     string The value of this node.
	 * @since      1.0.0
	 */
	public function getValue()
	{
		return $this->_value;
	}

	/**
	 * Retrieves the info of this value node.
	 * @return     array An array containing the info for this node.
	 * @since      1.0.0
	 */
	public function getNode()
	{
		return [
			'name' => $this->_name,
			'attributes' => $this->_attributes,
			'children' => $this->_childs,
			'value' => $this->_value,
		];
	}

	/**
	 * Determines if a named child exists. From ArrayAccess.
	 * @param      string $offset Offset to check
	 * @return     bool Whether the offset exists.
	 * @since      1.0.0
	 */
	public function offsetExists($offset): bool
	{
		return isset($this->_childs[$offset]);
	}

	/**
	 * Retrieves a named child. From ArrayAccess.
	 * @param      string $offset Offset to retrieve
	 * @return     ?ConfigValueHolder The child value.
	 * @since      1.0.0
	 */
	public function offsetGet($offset): ConfigValueHolder|null
	{
		if(!isset($this->_childs[$offset]))
			return null;
		return $this->_childs[$offset];
	}

	/**
	 * Inserts a named child. From ArrayAccess.
	 * @param      string $offset Offset to modify
	 * @param      ConfigValueHolder $value The child value.
	 * @since      1.0.0
	 */
	public function offsetSet($offset, $value): void
	{
		$this->_childs[$offset] = $value;
	}

	/**
	 * Deletes a named child. From ArrayAccess.
	 * @param      string $offset Offset to delete.
	 * @since      1.0.0
	 */
	public function offsetUnset($offset): void
	{
		unset($this->_childs[$offset]);
	}

	/**
	 * Returns an Iterator for the child nodes. From IteratorAggregate.
	 * @return     \ArrayIterator The iterator.
	 * @since      1.0.0
	 */
	public function getIterator(): \Traversable
	{
		return new \ArrayIterator($this->getChildren());
	}

	/**
	 * Retrieves the string representation of this value node. This is 
	 * currently only the value of the node.
	 * @return     string The string representation.
	 * @since      1.0.0
	 */
	public function __toString(): string
	{
		return $this->_value;
	}
}

?>