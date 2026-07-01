<?php
namespace Quiote\Config;

use Quiote\Context;

/**
 * XmlConfigHandler is the base config handler that deals with DOMDocuments
 * @since      1.0.0
 * @version    1.0.0
 */
abstract class XmlConfigHandler extends BaseConfigHandler implements IXmlConfigHandler
{
	/**
	 * @var        Context The context to work with (if available).
	 */
	protected $context = null;
	
	/**
	 * Initialize this ConfigHandler.
	 * @param      Context The context to work with (if available).
	 * @param      array        An associative array of initialization parameters.
	 * @throws     <b>InitializationException</b> If an error occurs while
	 *                                                 initializing the
	 *                                                 ConfigHandler
	 * @since      1.0.0
	 */
	public function initialize(?Context $context = null, $parameters = [])
	{
		$this->context = $context;
		$this->setParameters($parameters);
	}
}

?>