<?php
namespace Quiote\Config\Util\DOM;

/**
 * Extended DOMAttr class.
 * @since      1.0.0
 * @version    1.0.0
 */
class XmlConfigDomAttr extends \DOMAttr implements \Stringable
{
	public function __toString(): string
	{
		return (string) $this->getValue();
	}
	
	public function getValue()
	{
		return $this->nodeValue;
	}
}

?>