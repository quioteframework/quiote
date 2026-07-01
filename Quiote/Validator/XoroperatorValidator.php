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
	 * @throws     <b>ValidatorException</b> If the operator doesn't have 
	 *                                            exactly 2 child validators.
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
		$result1 = $child1->execute($this->validationParameters);
		if($result1 == Validator::CRITICAL) {
			$this->result = $result1;
			$this->throwError();
			return false;
		}
		
		$child2 = array_shift($children);
		$result2 = $child2->execute($this->validationParameters);
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

	#[\Override]
    public function reset() : void
	{
		parent::reset();
		$this->children = [];
		$this->result = Validator::SUCCESS;
	}
}

?>