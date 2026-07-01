<?php
namespace Quiote\Testing\PHPUnit\Constraint;

use Quiote\Action\Action;
use Quiote\Testing\BaseConstraintBecausePhpunitSucksAtBackwardsCompatibility;

/**
 * Constraint that checks if an Action handles an expected request method.
 * The Action instance is passed to the constructor.
 * @since      1.0.0
 * @version    1.0.0
 */
class ConstraintActionHandlesMethod extends BaseConstraintBecausePhpunitSucksAtBackwardsCompatibility
{
	/**
	 * @var        Action The Action instance.
	 */
	protected $actionInstance;
	
	/**
     * Class constructor.
     * @param      Action Instance of the Action to test.
     * @param      bool        Whether generic execute methods should be accepted.
     * @since      1.0.0
     * @param bool $acceptGeneric
     */
    public function __construct(Action $actionInstance, protected $acceptGeneric = true)
	{
		$this->actionInstance = $actionInstance;
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
		if(is_callable([$this->actionInstance, $executeMethod]) || ($this->acceptGeneric && is_callable([$this->actionInstance, 'execute']))) {
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
			'%1$s handles method',
			$this->actionInstance::class
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
				'Failed asserting that %1$s does not handle method "%2$s".',
				$this->actionInstance::class,
				$other
			);
		} else {
			return sprintf(
				'Failed asserting that %1$s handles method "%2$s".',
				$this->actionInstance::class,
				$other
			);
		}
	}
}
?>