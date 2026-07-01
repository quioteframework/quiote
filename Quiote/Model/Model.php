<?php
namespace Quiote\Model;

use Quiote\Context;

/**
 * Model provides a convention for separating business logic from 
 * application logic. When using a model you're providing a globally accessible
 * API for other modules to access, which will boost interoperability among 
 * modules in your web application.
 * @since      1.0.0
 * @version    1.0.0
 */
abstract class Model implements IModel
{

	protected $_contextName;
	/**
	 * @var        Context An Context instance.
	 */
	protected $context = null;

	/**
	 * Retrieve the current application context.
	 * @return     Context The current Context instance.
	 * @since      1.0.0
	 */
	public final function getContext()
	{
		return $this->context;
	}

	/**
	 * Initialize this model.
	 * @param      Context The current application context.
	 * @since      1.0.0
	 */
	public function initialize(Context $context, array $parameters = [])
	{
		$this->context = $context;
	}

	/**
	 * Pre-serialization callback.
	 * Will set the name of the context and exclude the instance from serializing.
	 * @since      1.0.0
	 */
	public function __sleep()
	{
		$this->_contextName = $this->context->getName();
		$arr = get_object_vars($this);
		unset($arr['context']);
		return array_keys($arr);
	}

	/**
	 * Post-unserialization callback.
	 * Will restore the context based on the names set by __sleep.
	 * @since      1.0.0
	 */
	public function __wakeup()
	{
		$this->context = Context::getInstance($this->_contextName);
		unset($this->_contextName);
	}
}

?>