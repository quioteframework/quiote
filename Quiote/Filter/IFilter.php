<?php
namespace Quiote\Filter;

use Quiote\Context;
use Quiote\Controller\ExecutionContainer;

/**
 * Filter provides a way for you to intercept incoming requests or outgoing
 * responses.
 * @since      1.0.0
 * @version    1.0.0
 */
interface IFilter
{
	/**
	 * Execute this filter.
	 * @param      FilterChain The filter chain.
	 * @param      ExecutionContainer The current execution container.
	 * @since      1.0.0
	 */
	public function execute(FilterChain $filterChain, ExecutionContainer $container);

	/**
	 * Retrieve the current application context.
	 * @return     Context The current Context instance.
	 * @since      1.0.0
	 */
	public function getContext();

	/**
	 * Initialize this Filter.
	 * @param      Context The current application context.
	 * @param      array        An associative array of initialization parameters.
	 * @throws     <b>InitializationException</b> If an error occurs while
	 *                                                 initializing this Filter.
	 * @since      1.0.0
	 */
	public function initialize(Context $context, array $parameters = []);
}

?>