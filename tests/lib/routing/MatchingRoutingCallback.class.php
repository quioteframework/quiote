<?php

use Quiote\Routing\RoutingCallback;

class MatchingRoutingCallback extends RoutingCallback
{
	#[\Override]
    public function onMatched(array &$parameters, $legacyContainer = null)
	{
		$parameters['callback'] = 'set';
		return true;
	}
}

?>