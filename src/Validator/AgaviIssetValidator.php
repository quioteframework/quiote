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
namespace Agavi\Validator;

/**
 * AgaviIssetValidator verifies a parameter is set
 * 
 * The content of the input value is not verified in any manner, it is only
 * checked if the input value exists. (see isset() in PHP)
 *
 * @package    agavi
 * @subpackage validator
 *
 * @author     Dominik del Bondio <ddb@bitxtender.com>
 * @copyright  Authors
 * @copyright  The Agavi Project
 *
 * @since      0.11.0
 *
 * @version    $Id$
 */
class AgaviIssetValidator extends AgaviValidator
{
	/**
	 * We need to return true here when this validator is required, because 
	 * otherwise the is*ValueEmpty check would make empty but set fields not 
	 * reach the validate method.
	 *
	 * @see        AgaviValidator::checkAllArgumentsSet
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @since      0.11.0
	 */
	#[\Override]
    protected function checkAllArgumentsSet($throwError = true)
	{
		if (getenv('AGAVI_DEBUG_VALIDATION')) {
			\Agavi\Logging\AgaviDebugLogger::debug('[AgaviIssetValidator][checkAllArgumentsSet] required=' . var_export($this->getParameter('required', true), true), $this->getContext());
		}
		if($this->getParameter('required', true)) {
			if (getenv('AGAVI_DEBUG_VALIDATION')) {
				\Agavi\Logging\AgaviDebugLogger::debug('[AgaviIssetValidator][checkAllArgumentsSet] returning TRUE (required)', $this->getContext());
			}
			return true;
		} else {
			$result = parent::checkAllArgumentsSet($throwError);
			if (getenv('AGAVI_DEBUG_VALIDATION')) {
				\Agavi\Logging\AgaviDebugLogger::debug('[AgaviIssetValidator][checkAllArgumentsSet] parent returned ' . var_export($result, true), $this->getContext());
			}
			return $result;
		}
	}

	/**
	 * Validates the input.
	 * 
	 * @return     bool The value is set.
	 * 
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @since      0.11.0
	 */
	protected function validate()
	{
		$params = $this->validationParameters->getAll($this->getParameter('source'));

		foreach($this->getArguments() as $argument) {
			if (getenv('AGAVI_DEBUG_VALIDATION')) {
				\Agavi\Logging\AgaviDebugLogger::debug('[AgaviIssetValidator][validate] argument=' . ($argument===''?'<empty>':$argument) . ' curBase=' . $this->curBase->__toString(), $this->getContext());
			}
			if(!$this->curBase->hasValueByChildPath($argument, $params)) {
				if (getenv('AGAVI_DEBUG_VALIDATION')) {
					\Agavi\Logging\AgaviDebugLogger::debug('[AgaviIssetValidator][validate] hasValueByChildPath returned FALSE', $this->getContext());
				}
				$this->throwError();
				return false;
			}
			if (getenv('AGAVI_DEBUG_VALIDATION')) {
				\Agavi\Logging\AgaviDebugLogger::debug('[AgaviIssetValidator][validate] hasValueByChildPath returned TRUE', $this->getContext());
			}
		}

		return true;
	}
}

?>