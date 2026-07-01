<?php
namespace Quiote\Filter;

use Quiote\Context;
use Quiote\Controller\ExecutionContainer;

/**
 * ExecutionTimeFilter tracks the length of time it takes for an entire
 * request to be served starting with the dispatch and ending when the last 
 * action request has been served.
 * <b>Optional parameters:</b>
 * # <b>comment</b> - [Yes] - Should we add an HTML comment to the end of each
 *                            output with the execution time?
 * # <b>replace</b> - [No] - If this exists, every occurrence of the value in the
 *                           client response will be replaced by the execution
 *                           time.
 * @since      1.0.0
 * @version    1.0.0
 */
class ExecutionTimeFilter extends Filter implements IGlobalFilter, IActionFilter
{
	/**
	 * Execute this filter.
	 * @param      FilterChain        The filter chain.
	 * @param      ExecutionContainer The current execution container.
	 * @throws     <b>FilterException</b> If an error occurs during execution.
	 * @since      1.0.0
	 */
	#[\Override]
    public function execute(FilterChain $filterChain, ExecutionContainer $container)
	{
		
	}

	/**
	 * Initialize this filter.
	 * @param      Context The current application context.
	 * @param      array        An associative array of initialization parameters.
	 * @throws     <b>FilterException</b> If an error occurs during 
	 *                                         initialization.
	 * @since      1.0.0
	 */
	#[\Override]
    public function initialize(Context $context, array $parameters = [])
	{
		// set defaults
		$this->setParameter('comment', true);
		$this->setParameter('replace', null);
		$this->setParameter('output_types', null);

		// initialize parent
		parent::initialize($context, $parameters);
	}
}

?>