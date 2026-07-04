<?php
namespace Quiote\Config;

use Quiote\Context;
use Quiote\Config\Util\DOM\XmlConfigDomDocument;

/**
 * IXmlConfigHandler is the interface that config handlers may implement to
 * indicate that they wish to process a DOMDocument directly.
 * @since      1.0.0
 * @version    1.0.0
 */
interface IXmlConfigHandler
{
	/**
	 * Initialize this ConfigHandler.
	 * @param      ?Context $context The context to work with (if available).
	 * @param      array        $parameters An associative array of initialization parameters.
	 * @throws     \Quiote\Exception\InitializationException If an error occurs while
	 *                                                 initializing the
	 *                                                 ConfigHandler
	 * @since      1.0.0
	 */
	public function initialize(?Context $context = null, $parameters = []);
	
	/**
	 * Execute this configuration handler.
	 * @param      XmlConfigDomDocument $document The document to parse.
	 * @return     string Data to be written to a cache file.
	 * @throws     \Quiote\Exception\ParseException If a requested configuration file is
	 *                                        improperly formatted.
	 * @since      1.0.0
	 */
	public function execute(XmlConfigDomDocument $document);
}

?>