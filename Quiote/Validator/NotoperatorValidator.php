<?php
namespace Quiote\Validator;

use Quiote\Exception\ValidatorException;
use Symfony\Contracts\Service\ResetInterface;

/**
 * NOTOperatorValidator succeeds if the sub-validator failed
 * Parameters:
 *   'skip_errors' do not submit errors of child validators to validator manager
 * @since      1.0.0
 * @version    1.0.0
 */
class NotoperatorValidator extends OperatorValidator implements ResetInterface
{
	/**
	 * Checks if operator has more then one child validator.
	 * @throws     ValidatorException If the operator has more then
	 *                                            one child validator
	 * @return     void
	 * @since      1.0.0
	 */
	protected function checkValidSetup()
	{
		if(count($this->children) != 1) {
			throw new ValidatorException('NOT allows only 1 child validator');
		}
	}

	/**
     * Adds a validation result for a given field.
     * @param      Validator $validator The validator.
     * @param      string $fieldname The name of the field which has been validated.
     * @param      int    $result The result of the validation.
     * @return     void
     * @since      1.0.0
     */
    #[\Override]
    #[\Deprecated(message: '1.0.0')]
    public function addFieldResult($validator, $fieldname, $result)
	{
		// prevent reporting of any child validators
	}

	/**
	 * Adds a intermediate result of an validator for the given argument
	 * @param      ValidationArgument $argument The argument
	 * @param      int                     $result The arguments result.
	 * @param      ?Validator          $validator The validator (if the error was caused
	 *                                     inside a validator).
	 * @return     null
	 * @since      1.0.0
	 */
	#[\Override]
    public function addArgumentResult(ValidationArgument $argument, $result, $validator = null)
	{
		// prevent reporting of any child validators
		return null;
	}

	/**
	 * Adds an incident to the validation result. 
	 * @param      ValidationIncident $incident The incident.
	 * @return     null
	 * @since      1.0.0
	 */
	#[\Override]
    public function addIncident(ValidationIncident $incident)
	{
		// prevent reporting of any child validators
		return null;
	}

	/**
	 * Validates the operator by returning the inverse result of the child 
	 * validator
	 * @return     bool True if the child validator failed.
	 * @since      1.0.0
	 */
	protected function validate()
	{
		$children = $this->children;
		$child = array_shift($children);
		$result = $child->execute($this->validationParameters);
		if($result == Validator::CRITICAL || $result == Validator::SUCCESS) {
			$this->result = max(Validator::ERROR, $result);
			$this->throwError(null, $child->getFullArgumentNames());
			return false;
		} else {
			// lets mark the fields of the child validator all as successful
			$affectedFields = $child->getFullArgumentNames();
			foreach($affectedFields as $field) {
				parent::addArgumentResult(new ValidationArgument($field, $this->getParameter('source')), Validator::SUCCESS, $this);
			}
			return true;
		}
	}	

	#[\Override]
    public function reset() : void
	{
		parent::reset();
		$this->children = [];
		$this->result = Validator::SUCCESS;
	} 
}