<?php
namespace Sandbox\Models;

use Quiote\Model\Model;

/**
 * A model whose constructor takes the locator's parameters, so the tests can tell the
 * "spread into the constructor" path from the "initialize() only" one. Variadic because the
 * locator spreads the parameter array rather than passing it whole.
 */
class ConstructorTestModel extends Model
{
	/** @var array<int, mixed> */
	public readonly array $args;

	public function __construct(mixed ...$args)
	{
		$this->args = array_values($args);
	}
}
