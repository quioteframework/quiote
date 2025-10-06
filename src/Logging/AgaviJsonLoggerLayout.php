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
namespace Agavi\Logging;
/**
 * AgaviJsonLoggerLayout is an AgaviLoggerLayout that will return a JSON
 * representation of the AgaviLoggerMessage or parts of it, depending on the
 * configuration.
 * 
 * Parameter "mode" controls the four possible modes of operation:
 *   'parameters' - serialize all parameters of the message
 *   'full'       - serialize the entire AgaviLoggerMessage object
 *   'message'    - serialize the value of AgaviLoggerMessage::getMessage()
 *   'parameter'  - serialize only one parameter of the object. By default, this
 *                  is "message"; can be changed using parameter "parameter".
 * Parameter "parameter" controls which parameter of the AgaviLoggerMessage
 * object is used when "mode" is "parameter". Defaults to "message".
 *
 * @package    agavi
 * @subpackage logging
 *
 * @copyright  Authors
 * @copyright  The Agavi Project
 *
 * @since      1.0.4
 *
 * @version    $Id$
 */
class AgaviJsonLoggerLayout extends AgaviLoggerLayout
{
	/**
	 * Format a message.
	 *
	 * @param      AgaviLoggerMessage An AgaviLoggerMessage instance.
	 *
	 * @return     string The AgaviLoggerMessage object as a JSON-encoded string.
	 *
	 * @since      1.0.4
	 */
	public function format(AgaviLoggerMessage $message)
	{
		$value = match ($this->getParameter('mode', 'parameters')) {
            'full' => $message,
            'message' => $message->getMessage(),
            'parameter' => $message->getParameter($this->getParameter('parameter', 'message')),
            default => $message->getParameters(),
        };
		
		return json_encode($value);
	}
}

?>