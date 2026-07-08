<?php
namespace Quiote\Validator;

use Quiote\Exception\ValidatorException;

/**
 * IssetValidator verifies a parameter is set
 * The content of the input value is not verified in any manner, it is only
 * checked if the input value exists. (see isset() in PHP)
 * @since      1.0.0
 * @version    1.0.0
 */
class IssetValidator extends Validator
{
	/**
	 * We need to return true here when this validator is required, because 
	 * otherwise the is*ValueEmpty check would make empty but set fields not 
	 * reach the validate method.
	 * @see        Validator::checkAllArgumentsSet
	 * @since      1.0.0
	 */
	#[\Override]
    protected function checkAllArgumentsSet($throwError = true)
	{
		$logger = \Quiote\Logging\Log::for($this);
		if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
			$logger->debug('[IssetValidator][checkAllArgumentsSet] required=' . var_export($this->getParameter('required', true), true));
		}
		if($this->getParameter('required', true)) {
			if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
				$logger->debug('[IssetValidator][checkAllArgumentsSet] returning TRUE (required)');
			}
			return true;
		} else {
			$result = parent::checkAllArgumentsSet($throwError);
			if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
				$logger->debug('[IssetValidator][checkAllArgumentsSet] parent returned ' . var_export($result, true));
			}
			return $result;
		}
	}

	/**
	 * Validates the input.
	 * @return     bool The value is set.
	 * @since      1.0.0
	 */
	protected function validate()
	{
		$curBase = $this->curBase;
		$validationParameters = $this->validationParameters;
		if ($curBase === null || $validationParameters === null) {
			throw new ValidatorException('Validator "' . ($this->getName() ?? '?') . '" has no base path/request; validate() ran before setParentContainer()/execute() supplied them.');
		}

		$params = $validationParameters->getAll($this->getParameter('source'));

		$logger = \Quiote\Logging\Log::for($this);
		foreach($this->getArguments() as $argument) {
			if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
				$logger->debug('[IssetValidator][validate] argument=' . ($argument===''?'<empty>':$argument) . ' curBase=' . $curBase->__toString());
			}
			if(!$curBase->hasValueByChildPath($argument, $params)) {
				if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
					$logger->debug('[IssetValidator][validate] hasValueByChildPath returned FALSE');
				}
				$this->throwError();
				return false;
			}
			if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
				$logger->debug('[IssetValidator][validate] hasValueByChildPath returned TRUE');
			}
		}

		return true;
	}
}

?>