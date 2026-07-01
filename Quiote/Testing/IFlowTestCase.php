<?php
namespace Quiote\Testing;

/**
 * IFlowTestCase is the interface that all flow tests must implement
 * @since      1.0.0
 * @version    1.0.0
 */
interface IFlowTestCase extends ITestCase
{
	/**
	 * dispatch the request
	 * @since      1.0.0
	 */
	public function dispatch();
}

?>