<?php
namespace Quiote\Routing;

use Quiote\Util\ArrayPathDefinition;

/**
 * RoutingArraySource allows you to provide array sources for the routing
 * @since      1.0.0
 * @version    1.0.0
 */
class RoutingArraySource implements IRoutingSource
{
	/**
	 * @var        array An array with data.
	 */
	protected $data = [];

	/**
	 * Constructor.
	 * @param      array $data An array with data.
	 * @since      1.0.0
	 */
	public function __construct(array $data)
	{
		$this->data = $data;
	}

	/**
	 * Retrieves the value for a given entry from the source.
	 * @param      array $parts An array with the name parts for the entry.
	 * @return     mixed The value.
	 * @since      1.0.0
	 */
	public function getSource(array $parts)
	{
		return ArrayPathDefinition::getValue($parts, $this->data);
	}
}

?>