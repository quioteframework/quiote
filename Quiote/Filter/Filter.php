<?php
namespace Quiote\Filter;

use Quiote\Context;
use Quiote\Controller\ExecutionContainer;
use Quiote\Util\ParameterHolder;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Filter provides a way for you to intercept incoming requests or outgoing
 * responses.
 * @since      1.0.0
 * @version    1.0.0
 */
abstract class Filter extends ParameterHolder implements IFilter, ResetInterface
{
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
	 * Initialize this Filter.
	 * @param      Context The current application context.
	 * @param      array        An associative array of initialization parameters.
	 * @throws     <b>InitializationException</b> If an error occurs while
	 *                                                 initializing this Filter.
	 * @since      1.0.0
	 */
	public function initialize(Context $context, array $parameters = [])
	{
		$this->context = $context;

		$this->setParameters($parameters);
	}
	
	/**
	 * The default "execute" method, which does nothing.
	 * In the simplified version, filters just return to continue the chain.
	 * @param      FilterChain The filter chain.
	 * @param      ExecutionContainer The current execution container.
	 * @since      1.0.0
	 */
	public function execute(FilterChain $filterChain, ExecutionContainer $container)
	{
		// Default: do nothing, the simplified filter chain will continue automatically
	}

	/**
	 * Reset filter state for FrankenPHP worker compatibility.
	 * Clears filter-specific properties that could leak between requests.
	 * @since      1.0.0
	 */
	#[\Override]
    public function reset(): void
	{
		$this->context = null;
		
		// Reset parent parameter holder state
		parent::clearParameters();
	}
}

?>