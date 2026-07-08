<?php
namespace Quiote\Validator;

use Quiote\Config\Config;
use Quiote\Exception\ValidatorException;
use Quiote\Util\DecimalFormatter;

/**
 * NumberValidator verifies that a parameter is a number and allows you to
 * apply size constraints.
 * Parameters:
 *   'no_locale' do not use localized number format parsing with translation on
 *   'in_locale' locale to use for parsing rather than the current locale
 *   'type'      number type (int/integer or double/float)
 *   'cast_to'   type to cast to (int/integer or double/float)
 *   'min'       minimum value for the input
 *   'max'       maximum value for the input
 * @since      1.0.0
 * @version    1.0.0
 */
class NumberValidator extends Validator
{
	#[\Override]
	public static function getAcceptedParameters(): array
	{
		return array_merge(parent::getAcceptedParameters(), [
			'no_locale', 'in_locale', 'type', 'cast_to', 'min', 'max',
		]);
	}

	/**
	 * Validates the input
	 * @return     bool The input is valid number according to given parameters.
	 * @since      1.0.0
	 */
	protected function validate()
	{
		$value = $this->getData($this->getArgument());

		if(!is_scalar($value)) {
			// non scalar values would cause notices
			$this->throwError();
			return false;
		}

		$hasExtraChars = false;
		if(!is_int($value) && !is_float($value)) {
			$locale = null;
			if(Config::getBool('core.use_translation', false) && !$this->getParameter('no_locale', false)) {
				// core.use_translation can be enabled without a translation manager
				// actually being wired into the context (e.g. it was switched on
				// after bootstrap). Guard against a null manager so we degrade to
				// locale-less parsing instead of fataling on a null method call.
				$tm = $this->getContext()->getTranslationManager();
				if($tm !== null) {
					if($locale = $this->getParameter('in_locale')) {
						$locale = $tm->getLocale($locale);
					} else {
						$locale = $tm->getCurrentLocale();
					}
				}
			}
			
			$parsedValue = DecimalFormatter::parse((string) $value, $locale, $hasExtraChars);
		} else {
			$parsedValue = $value;
		}
		
		switch(strtolower((string) $this->getParameter('type'))) {
			case 'int':
			case 'integer':
				if(!is_int($parsedValue) || $hasExtraChars) {
					$this->throwError('type');
					return false;
				}
				
				break;
			
			case 'float':
			case 'double':
				if((!is_float($parsedValue) && !is_int($parsedValue)) || $hasExtraChars) {
					$this->throwError('type');
					return false;
				}
				
				break;
			
			default:
				if($parsedValue === false || $hasExtraChars) {
					$this->throwError('type');
					return false;
				}
		}

		if($this->hasParameter('min') && $parsedValue < $this->getParameter('min')) {
			$this->throwError('min');
			return false;
		}

		if($this->hasParameter('max') && $parsedValue > $this->getParameter('max')) {
			$this->throwError('max');
			return false;
		}
		
		switch(strtolower((string) $this->getParameter('cast_to', $this->getParameter('type')))) {
			case 'int':
			case 'integer':
				$parsedValue = (int) $parsedValue;
				break;
			
			case 'float':
			case 'double':
				$parsedValue = (float) $parsedValue;
				break;
		}

		if($this->hasParameter('export')) {
			$this->export($parsedValue);
		} else {
			// Persist casted numeric value back into request runtime parameters so subsequent validators/actions see normalized type.
			try {
				$validationParameters = $this->validationParameters;
				if($validationParameters === null) {
					throw new ValidatorException('Validator "' . ($this->getName() ?? '?') . '" has no request; validate() ran before execute() supplied one.');
				}
				$argumentName = $this->getArgument();
				if($argumentName !== null) {
					$this->validationParameters = $validationParameters->setParameter($argumentName, $parsedValue);
				}
			} catch(\Throwable) {}
		}
		
		return true;
	}
}

?>