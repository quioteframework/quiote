<?php

use Quiote\Routing\RoutingCallback;

class NonMatchingRoutingCallback extends RoutingCallback
{
	#[\Override]
    public function onMatched(array &$parameters, $legacyContainer = null)
	{
		return false;
	}
}

?>