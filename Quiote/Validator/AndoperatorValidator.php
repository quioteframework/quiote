<?php
namespace Quiote\Validator;

use Symfony\Contracts\Service\ResetInterface;

/**
 * ANDOperatorValidator only succeeds if all sub-validators succeeded
 * Parameters:
 *   'skip_errors' do not submit errors of child validators to validator manager
 *   'break'       break the execution of child validators after first failure
 * @since      1.0.0
 * @version    1.0.0
 */
class AndoperatorValidator extends OperatorValidator implements ResetInterface
{
	#[\Override]
	public static function getAcceptedParameters(): array
	{
		return array_merge(parent::getAcceptedParameters(), ['break']);
	}

	/**
	 * Validates the operator by executing the child validators.
	 * @return     bool True if all child validators resulted successful.
	 * @since      1.0.0
	 */
	protected function validate()
	{
		$return = true;

		foreach($this->children as $child) {
			$result = $child->execute($this->requireValidationParameters());
			// WebRequest is immutable: a child's export() (e.g. <ae:parameter name="export">)
			// replaces only the child's own copy. Fold it back into this operator's own copy so
			// ValidationManager::execute()'s getMutatedRequest() pickup -- which only looks at its
			// direct children, i.e. this operator, never the child nested inside it -- sees the
			// export, and so a later sibling sees an earlier one's export too.
			$this->validationParameters = $child->getMutatedRequest() ?? $this->validationParameters;
			$this->result = max($result, $this->result);
			if($result > Validator::SUCCESS) {
				// if one validator fails, the whole operator fails
				$return = false;
				$this->throwError();
				if($this->getParameter('break') || $result == Validator::CRITICAL) {
					break;
				}
			}
		}
		
		return $return;
	}

	#[\Override]
    public function reset() : void
	{
		parent::reset();;
		$this->result = Validator::SUCCESS;
	}
}

?>