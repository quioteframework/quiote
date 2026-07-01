<?php
namespace Quiote\Testing;

/**
 * IFragmentTestCase is the interface that all fragment tests must implement
 * @since      1.0.0
 * @version    1.0.0
 */
interface IFragmentTestCase extends ITestCase
{
	public function getContext();
}

?>