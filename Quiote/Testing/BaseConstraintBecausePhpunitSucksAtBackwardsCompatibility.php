<?php
namespace Quiote\Testing;

use PHPUnit\Framework\Constraint\Constraint;

/**
 * Base constraint that caters for breaking changes between PHPUnit 3.5 and 3.6.
 * Concrete constraints must implement match().
 * @since      1.0.0
 * @version    1.0.0
 */
abstract class BaseConstraintBecausePhpunitSucksAtBackwardsCompatibility extends Constraint
{
	/**
	 * Overridden function to cover differences between PHPUnit 3.5 and 3.6.
	 * Intentionally made final so people have to use match() from now on.
	 * match() should be abstract really, but isn't, the usual PHPUnit quality...
	 * @param      mixed  $other The item to evaluate.
	 * @param      string $description Additional information about the test (3.6+).
	 * @param      bool   $returnResult Whether to return a result or throw an exception (3.6+).
	 * @since      1.0.0
	 */
	#[\Override]
    public function evaluate($other, $description = '', $returnResult = false): ?bool
	{

			return parent::evaluate($other, $description, $returnResult);
		
	}
}

?>