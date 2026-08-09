<?php
namespace Quiote\Validator;

use Symfony\Contracts\Service\ResetInterface;

/**
 * OROperatorValidator succeeds if at least one sub-validators succeeded
 * Parameters:
 *   'skip_errors' do not submit errors of child validators to validator manager
 *   'break'       break the execution of child validators after first success
 * @since      1.0.0
 * @version    1.0.0
 */
class OroperatorValidator extends OperatorValidator implements ResetInterface
{
	/**
	 * Returns the base and OperatorValidator parameters plus 'break'.
	 *
	 * 'break' stops the group as soon as one child succeeds instead of running
	 * the remaining ones; a CRITICAL child result breaks out regardless of it.
	 * 'skip_errors' comes from OperatorValidator.
	 * @return     array<int, string> The accepted parameter names.
	 */
	#[\Override]
	public static function getAcceptedParameters(): array
	{
		return array_merge(parent::getAcceptedParameters(), ['break']);
	}

	/**
	 * Executes the child validators.
	 * @return     bool True if at least one child validator succeeded.
	 * @since      1.0.0
	 */
	protected function validate()
	{
		$return = false;

		foreach($this->children as $child) {
			$result = $child->execute($this->requireValidationParameters());
			// WebRequest is immutable: a child's export() (e.g. <ae:parameter name="export">)
			// replaces only the child's own copy. Fold it back into this operator's own copy so
			// ValidationManager::execute()'s getMutatedRequest() pickup -- which only looks at its
			// direct children, i.e. this operator, never the child nested inside it -- sees the export.
			$this->validationParameters = $child->getMutatedRequest() ?? $this->validationParameters;
			$this->result = max($this->result, $result);

			if($result == Validator::SUCCESS) {
				// if one child validator succeeds, the whole operator succeeds
				$return = true;
				$this->result = $result;
				if($this->getParameter('break')) {
					break;
				}
			} elseif($result == Validator::CRITICAL) {
				break;
			}
		}
		
		if(!$return) {
			$this->throwError();
		}

		return $return;
	}

	/**
	 * Returns the operator to its initial state for reuse across requests.
	 *
	 * Resets the inherited state through OperatorValidator::reset(), then
	 * drops the children and puts the result back to SUCCESS, so the
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