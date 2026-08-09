<?php
namespace Quiote\Validator;

use Quiote\Exception\ValidatorException;
use Symfony\Contracts\Service\ResetInterface;

/**
 * XOROperatorValidator succeeds if only one of two sub-validators 
 * succeeded
 * Parameters:
 *   'skip_errors'  don't submit errors of child validators to validator manager
 * @since      1.0.0
 * @version    1.0.0
 */
class XoroperatorValidator extends OperatorValidator implements ResetInterface
{
	/**
	 * Checks if this operator has a exactly 2 child validators.
	 * @throws     ValidatorException If the operator doesn't have
	 *                                            exactly 2 child validators.
	 * @return     void
	 * @since      1.0.0
	 */
	protected function checkValidSetup()
	{
		if(count($this->children) != 2) {
			throw new ValidatorException('XOR allows only exact 2 child validators');
		}
	}

	/**
	 * Validates the operator by returning the by XORing the results of the child
	 * validators.
	 * @return     bool True if exactly one child validator succeeded.
	 * @since      1.0.0
	 */
	protected function validate()
	{
		$children = $this->children;

		$child1 = array_shift($children);
		if ($child1 === null) {
			throw new ValidatorException('XOR operator has no first child validator to execute (checkValidSetup() should have rejected this setup already)');
		}
		$result1 = $child1->execute($this->requireValidationParameters());
		// WebRequest is immutable: a child's export() (e.g. <ae:parameter name="export">)
		// replaces only the child's own copy. Fold it back into this operator's own copy so
		// ValidationManager::execute()'s getMutatedRequest() pickup -- which only looks at its
		// direct children, i.e. this operator, never a child nested inside it -- sees the
		// export, and so the second child sees the first one's export too.
		$this->validationParameters = $child1->getMutatedRequest() ?? $this->validationParameters;
		if($result1 == Validator::CRITICAL) {
			$this->result = $result1;
			$this->throwError();
			return false;
		}

		$child2 = array_shift($children);
		if ($child2 === null) {
			throw new ValidatorException('XOR operator has no second child validator to execute (checkValidSetup() should have rejected this setup already)');
		}
		$result2 = $child2->execute($this->requireValidationParameters());
		$this->validationParameters = $child2->getMutatedRequest() ?? $this->validationParameters;
		if($result2 == Validator::CRITICAL) {
			$this->result = $result2;
			$this->throwError();
			return false;
		}
		
		$this->result = max($result1, $result2);
		
		if(($result1 == Validator::SUCCESS) xor ($result2 == Validator::SUCCESS)) {
			return true;
		} else {
			$this->throwError();
			return false;
		}
	}	

	/**
	 * Returns the operator to its initial state for reuse across requests.
	 *
	 * Resets the inherited state through OperatorValidator::reset(), then
	 * drops the two children and puts the result back to SUCCESS, so the
	 * validator has to be re-registered before it does anything again.
	 */
	#[\Override]
    public function reset() : void
	{
		parent::reset();
		$this->children = [];
		$this->result = Validator::SUCCESS;
	}
}

?>