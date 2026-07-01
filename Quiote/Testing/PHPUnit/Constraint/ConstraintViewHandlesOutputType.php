<?php

use Quiote\Testing\BaseConstraintBecausePhpunitSucksAtBackwardsCompatibility;
use Quiote\View\View;

/**
 * Constraint that checks if a View handles an expected Output Type.
 * The View instance is passed to the constructor.
 * @since      1.0.0
 * @version    1.0.0
 */
class ConstraintViewHandlesOutputType extends BaseConstraintBecausePhpunitSucksAtBackwardsCompatibility
{
	/**
	 * @var        View The View instance.
	 */
	protected $viewInstance;
	
	/**
     * constructor
     * @param      View Instance of the View to test
     * @param      bool      Whether generic execute methods should be accepted.
     * @since      1.0.0
     * @param bool $acceptGeneric
     */
    public function __construct(View $viewInstance, /**
     * @var        bool Whether generic 'execute' methods should be accepted.
     */
    protected $acceptGeneric = false)
	{
		$this->viewInstance = $viewInstance;
	}
	
	/**
	 * Evaluates the constraint for parameter $other. Returns TRUE if the
	 * constraint is met, FALSE otherwise.
	 * @param      mixed Value or object to evaluate.
	 * @return     bool The result of the evaluation.
	 * @since      1.0.0
	 */
	#[\Override]
    public function matches($other): bool
	{
		$executeMethod = 'execute' . $other;
		if(is_callable([$this->viewInstance, $executeMethod]) || ($this->acceptGeneric && is_callable($this->viewInstance->execute(...)))) {
			return true;
		}
		
		return false;
	}
	
	/**
	 * Returns a string representation of the constraint.
	 * @return     string The string representation.
	 * @since      1.0.0
	 */
	public function toString(): string
	{
		return sprintf(
			'%1$s handles output type',
		
			$this->viewInstance::class
		);
	}
	
	/**
	 * Returns a custom error description.
	 * @param      mixed  Value or object to evaluate.
	 * @param      string The original description.
	 * @param      bool   true if the constraint was negated.
	 * @return     string The error description.
	 * @since      1.0.0
	 */
	protected function customFailureDescription($other, $description, $not)
	{
		if($not) {
			return sprintf(
				'Failed asserting that %1$s does not handle output type "%2$s".',
				$this->viewInstance::class,
				$other
			);
		} else {
			return sprintf(
				'Failed asserting that %1$s handles output type "%2$s".',
				$this->viewInstance::class,
				$other
			);
		}
	}
}
?>