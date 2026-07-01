<?php
namespace Quiote\Filter;

use Quiote\Controller\ExecutionContainer;

/**
 * DispatchFilter is the last in the chain of global filters and executes
 * the execution container, also re-setting the container's response to the
 * return value of the execution, so responses from forwards are passed along
 * properly.
 * @since      1.0.0
 * @version    1.0.0
 */
// Legacy no-op stub retained for BC; will be removed in a future major version.
final class DispatchFilter
{
	/**
	 * Execute this filter.
	 * The DispatchFilter executes the execution container.
	 * @param      FilterChain The filter chain.
	 * @param      ExecutionContainer The current execution container.
	 * @throws     <b>FilterException</b> If an error occurs during execution.
	 * @since      1.0.0
	 */
	// Intentionally empty
}

?>