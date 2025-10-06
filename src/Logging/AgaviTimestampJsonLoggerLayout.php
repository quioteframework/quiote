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

use DateTimeImmutable;
use IntlDateFormatter;

/**
 * AgaviTimestampLoggerLayout prepends the current date and time to the message.
 *
 * @package    agavi
 * @subpackage logging
 *
 * @author     David Zülke <dz@bitxtender.com>
 * @copyright  Authors
 * @copyright  The Agavi Project
 *
 * @since      0.11.0
 *
 * @version    $Id$
 */
class AgaviTimestampJsonLoggerLayout extends AgaviLoggerLayout
{
	/**
	 * Format a message.
	 *
	 * @param      AgaviLoggerMessage An AgaviLoggerMessage instance.
	 *
	 * @return     string A formatted message.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function format(AgaviLoggerMessage $message)
	{
		$dt = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
		$level = $message->getLevel();
		
		$levelStr = match ($level) {
			AgaviLogger::ERROR => 'ERROR',
			AgaviLogger::WARN => 'WARN',			
			AgaviLogger::INFO => 'INFO',
			AgaviLogger::DEBUG => 'DEBUG',
			default => (string)$level,
		};

		$value = ["timestamp" => $dt->format(DateTimeImmutable::ATOM), "level" => $levelStr, "message" => sprintf($this->getParameter('message_format', '%1$s'), $message->__toString())];
		$message = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		return $message;
	}
}