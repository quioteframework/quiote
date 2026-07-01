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
	 * @param      Context The context to work with (if available).
	 * @param      array        An associative array of initialization parameters.
	 * @throws     <b>InitializationException</b> If an error occurs while
	 *                                                 initializing the
	 *                                                 ConfigHandler
	 * @since      1.0.0
	 */
	public function initialize(?Context $context = null, $parameters = []);
	
	/**
	 * Execute this configuration handler.
	 * @param      XmlConfigDomDocument The document to parse.
	 * @return     string Data to be written to a cache file.
	 * @throws     <b>ParseException</b> If a requested configuration file is
	 *                                        improperly formatted.
	 * @since      1.0.0
	 */
	public function execute(XmlConfigDomDocument $document);
}

?>