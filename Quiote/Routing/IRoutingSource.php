<?php
namespace Quiote\Routing;

/**
 * IRoutingSource allows you to provide sources for the routing
 * @since      1.0.0
 * @version    1.0.0
 */
interface IRoutingSource
{
	/**
	 * Retrieves the value for a given entry from the source.
	 * @param      array<int, string> $parts An array with the name parts for the entry.
	 * @return     mixed The value.
	 * @since      1.0.0
	 */
	public function getSource(array $parts);
}

?>