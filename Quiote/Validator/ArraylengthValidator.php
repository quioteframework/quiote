<?php
namespace Quiote\Validator;

/**
 * ArraylengthValidator verifies the length (count()) constraints for an array
 * Parameters:
 *   'min'       The array should contain at least 'min' elements
 *   'max'       The array should contain at most 'max' elements
 * @since      1.0.0
 * @version    1.0.0
 */
class ArraylengthValidator extends Validator
{
	#[\Override]
	public static function getAcceptedParameters(): array
	{
		return array_merge(parent::getAcceptedParameters(), ['min', 'max']);
	}

	/**
	 * Returns whether all arguments are set in the validation input parameters.
	 * Set means anything but empty string.
	 * Different to Validator::checkAllArgumentsSet() in that it will not
	 * rely on the isValueEmpty() information from the respective request data
	 * holder class, but instead pull the value and check if it is an array.
	 * @param      bool Whether an error should be thrown for each missing 
	 *                  argument if this validator is required.
	 * @return     bool Whether the arguments are set.
	 * @since      1.0.0
	 */
	#[\Override]
    protected function checkAllArgumentsSet($throwError = true)
	{
		// copied from Validator::checkAllArgumentsSet()
		$isRequired = $this->getParameter('required', true);
		$paramType = $this->getParameter('source');
		$result = true;

		foreach($this->getArguments() as $argument) {
			$pName = $this->curBase->pushRetNew($argument)->__toString();
			// can't do this:
			// if($this->validationParameters->isValueEmpty($paramType, $pName)) {
			// Historically file value emptiness checks depended on request data holder internals.
			// With PSR-7 migration and removal of legacy data holders, we conservatively ensure the
			// parameter both exists and is an array before counting.
				if(!$this->validationParameters->hasParameter($pName) || !is_array($this->validationParameters->getParameter($pName))) {
					if($throwError && $isRequired) {
					$this->throwError('required', $pName);
				}
				$result = false;
			}
		}
		return $result;
	}
	
	/**
	 * Validates the input.
	 * @return     bool
	 * @since      1.0.0
	 */
	protected function validate()
	{
		$data = $this->getData($this->getArgument());
		if(!is_array($data)) {
			// we can only count() arrays
			$this->throwError();
			return false;
		}
		
		$count = count($data);
		
		if($this->hasParameter('min') && $count < $this->getParameter('min')) {
			$this->throwError('min');
			return false;
		}
		
		if($this->hasParameter('max') && $count > $this->getParameter('max')) {
			$this->throwError('max');
			return false;
		}
		
		return true;
	}
}

?>