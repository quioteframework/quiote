<?php
namespace Quiote\Validator;

use Quiote\Util\VirtualArrayPath;

/**
 * IValidatorContainer is an interface for classes which contains several
 * child validators
 * @since      1.0.0
 * @version    1.0.0
 */
interface IValidatorContainer
{
	/**
	 * Adds a new validator to the list of children.
	 * @param      Validator $validator The new child.
	 * @return     void
	 * @since      1.0.0
	 */
	public function addChild(Validator $validator);

	/**
	 * Adds a intermediate result of an validator for the given argument
	 * @param      ValidationArgument $argument The argument
	 * @param      int                     $result The arguments result.
	 * @param      ?Validator          $validator The validator (if the error was caused
	 *                                     inside a validator).
	 * @return     void
	 * @since      1.0.0
	 */
	public function addArgumentResult(ValidationArgument $argument, $result, $validator = null);

	/**
	 * Adds an incident to the validation result.
	 * @param      ValidationIncident $incident The incident.
	 * @return     void
	 * @since      1.0.0
	 */
	public function addIncident(ValidationIncident $incident);

	/**
	 * Returns a named child validator.
	 * @param      string $name The name of the child validator.
	 * @return     Validator The named child validator.
	 * @since      1.0.0
	 */
	public function getChild($name);

	/**
	 * Returns all child validators.
	 * @return     array<string, Validator> An array of Validator instances.
	 * @since      1.0.0
	 */
	public function getChilds();

	/**
	 * Fetches the dependency manager
	 * @return     DependencyManager The dependency manager to be used
	 *                                    by child validators.
	 * @since      1.0.0
	 */
	public function getDependencyManager();

	/**
	 * Return the current base path used for relative argument resolution.
	 * Implementations like ValidationManager provide this; validators rely on it.
	 * @return VirtualArrayPath
	 */
	public function getBase();

}
?>