<?php
namespace Quiote\Testing;

/**
 * IUnitTestCase is the interface that all unit tests must implement
 * @since      1.0.0
 * @version    1.0.0
 */
interface IUnitTestCase extends ITestCase
{
	public function getContext();
}

?>