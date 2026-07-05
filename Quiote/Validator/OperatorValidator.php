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
	#[\Override]
	public static function getAcceptedParameters(): array
	{
		return array_merge(parent::getAcceptedParameters(), ['skip_errors']);
	}

	/**
	 * @var        array<string, Validator> The child validators.
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
	 * @throws     \Quiote\Exception\ValidatorException If the  quantity of child
	 *                                           validators is invalid
	 * @return     void
	 * @since      1.0.0
	 */
	protected function checkValidSetup()
	{
	}

	/**
	 * Shutdown method, for shutting down the model etc.
	 * @return     void
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
     * @param      Validator $validator The validator.
     * @param      string $fieldname The name of the field which has been validated.
     * @param      int $result The result of the validation.
     * @return     mixed
     * @since      1.0.0
     */
    #[\Deprecated(message: '1.0.0')]
    public function addFieldResult($validator, $fieldname, $result)
	{
		// IValidatorContainer does not declare addFieldResult() (it's a
		// deprecated, legacy method some containers still implement), so
		// this cannot be called through the interface type alone.
		if($this->parentContainer !== null && method_exists($this->parentContainer, 'addFieldResult')) {
			return $this->parentContainer->addFieldResult($validator, $fieldname, $result);
		}
	}

	/**
	 * Adds a intermediate result of an validator for the given argument
	 * @param      ValidationArgument $argument The argument
	 * @param      int $result The arguments result.
	 * @param      Validator $validator The validator (if the error was caused
	 *                                     inside a validator).
	 * @return     null
	 * @since      1.0.0
	 */
	public function addArgumentResult(ValidationArgument $argument, $result, $validator = null)
	{
		if($this->parentContainer !== null) {
			$this->parentContainer->addArgumentResult($argument, $result, $validator);
		}
		return null;
	}

	/**
	 * Adds an incident to the validation result. 
	 * @param      ValidationIncident $incident The incident.
	 * @return     null
	 * @since      1.0.0
	 */
	public function addIncident(ValidationIncident $incident)
	{
		if($this->parentContainer !== null) {
			$this->parentContainer->addIncident($incident);
		}
		return null;
	}

	/**
	 * Adds new child validator.
	 * @param      Validator $validator The new child validator.
	 * @return     void
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
	 * @param      string $name The name of the child validator.
	 * @return     Validator The named child validator.
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
	 * @return     array<string, Validator> An array of Validator instances.
	 * @since      1.0.0
	 */
	public function getChilds()
	{
		return $this->children;
	}

	/**
	 * Registers an array of validators.
	 * @param      array<Validator> $validators The array of validators.
	 * @return     void
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
	 * @param      WebRequest $parameters The parameters which should be validated.
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
		foreach($this->children as $child) {
			$child->reset();
		}
		$this->children = [];
		$this->result = Validator::SUCCESS;
	}
}