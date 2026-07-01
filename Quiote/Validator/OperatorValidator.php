<?php
namespace Quiote\Validator;

use Quiote\Request\WebRequest;
use InvalidArgumentException;
use Symfony\Contracts\Service\ResetInterface;

/**
 * OperatorValidator
 * Operators group a couple if validators...
 * @since      1.0.0
 * @version    1.0.0
 */
abstract class OperatorValidator extends Validator implements IValidatorContainer, ResetInterface
{
	/**
	 * @var        array The child validators.
	 */
	protected $children = [];

	/**
	 * @var        int The highest error severity in the container.
	 */
	protected $result = Validator::SUCCESS;
	

	/**
	 * Method for checking the validity of child validators.
	 * Some operators (XOR and NOT) need a specific quantity of child
	 * validators so they implement an algorithm that checks of the setup
	 * is valid. This method is run first when execute() is invoked and
	 * should throw an exception if the setup is invalid.
	 * @throws     <b>ValidatorException</b> If the  quantity of child 
	 *                                           validators is invalid
	 * @since      1.0.0
	 */
	protected function checkValidSetup()
	{
	}
	
	/**
	 * Shutdown method, for shutting down the model etc.
	 * @since      1.0.0
	 */
	public function shutdown()
	{
		foreach($this->children as $child) {
			$child->shutdown();
		}
	}
	

	/**
     * Adds a validation result for a given field.
     * @param      Validator The validator.
     * @param      string The name of the field which has been validated.
     * @param      int    The result of the validation.
     * @since      1.0.0
     */
    #[\Deprecated(message: '1.0.0')]
    public function addFieldResult($validator, $fieldname, $result)
	{
		if($this->parentContainer !== null) {
			return $this->parentContainer->addFieldResult($validator, $fieldname, $result);
		}
	}

	/**
	 * Adds a intermediate result of an validator for the given argument
	 * @param      ValidationArgument The argument
	 * @param      int                     The arguments result.
	 * @param      Validator          The validator (if the error was caused
	 *                                     inside a validator).
	 * @since      1.0.0
	 */
	public function addArgumentResult(ValidationArgument $argument, $result, $validator = null)
	{
		if($this->parentContainer !== null) {
			return $this->parentContainer->addArgumentResult($argument, $result, $validator);
		}
	}

	/**
	 * Adds an incident to the validation result. 
	 * @param      ValidationIncident The incident.
	 * @since      1.0.0
	 */
	public function addIncident(ValidationIncident $incident)
	{
		if($this->parentContainer !== null) {
			return $this->parentContainer->addIncident($incident);
		}
	}

	/**
	 * Adds new child validator.
	 * @param      Validator The new child validator.
	 * @since      1.0.0
	 */
	public function addChild(Validator $validator)
	{
		$name = $validator->getName();
		if(isset($this->children[$name])) {
			throw new InvalidArgumentException('A validator with the name "' . $name . '" already exists');
		}

		$this->children[$name] = $validator;
		$validator->setParentContainer($this);
	}

	/**
	 * Returns a named child validator.
	 * @param      Validator The child validator.
	 * @since      1.0.0
	 */
	public function getChild($name)
	{
		if(!isset($this->children[$name])) {
			throw new InvalidArgumentException('A validator with the name "' . $name . '" does not exist');
		}

		return $this->children[$name];
	}

	/**
	 * Returns all child validators.
	 * @return     array An array of Validator instances.
	 * @since      1.0.0
	 */
	public function getChilds()
	{
		return $this->children;
	}

	/**
	 * Registers an array of validators.
	 * @param      array The array of validators.
	 * @since      1.0.0
	 */
	public function registerValidators(array $validators)
	{
		foreach($validators as $validator) {
			$this->addChild($validator);
		}
	}
	
	/**
	 * Gets parent's dependency manager.
	 * @return     DependencyManager The parent's dependency manager.
	 * @since      1.0.0
	 */
    #[\Override]
    public function getDependencyManager()
	{
		return $this->parentContainer->getDependencyManager();
	}

	/**
	 * Returns the result from the error manager.
	 * @return     int The result of the validation process.
	 * @since      1.0.0
	 */
	public function getResult()
	{
		return $this->result;
	}

	/**
	 * Executes the validator.
	 * Executes the operators validate()-Method after checking the quantity
	 * of child validators with checkValidSetup().
	 * @param      WebRequest The parameters which should be validated.
	 * @return     int The result of validation (SUCCESS, NONE, NOTICE, ERROR, CRITICAL).
	 * @since      1.0.0
	 */
    #[\Override]
    public function execute(WebRequest $parameters)
	{
		// check if we have a valid setup of validators
		$this->checkValidSetup();
		
		$result = parent::execute($parameters);
		if($result != Validator::SUCCESS && !$this->getParameter('skip_errors') && $this->result == Validator::CRITICAL) {
			/*
			 * one of the child validators resulted with CRITICAL
			 * we change our operator's result to CRITICAL, too so the
			 * surrounding validator container is aware of the critical
			 * result and can abort further validation... 
			 */
			$result = Validator::CRITICAL;
		}
		
		return $result;
	}

	#[\Override]
    public function reset() : void
	{
		$this->children = [];
		$this->result = Validator::SUCCESS;
		foreach($this->children as $child) {
			$child->reset();
		}
		
	}
}