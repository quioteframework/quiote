<?php

use Agavi\Routing\AgaviRoutingCallback;

class NonMatchingRoutingCallback extends AgaviRoutingCallback
{
	#[\Override]
    public function onMatched(array &$parameters, $legacyContainer = null)
	{
		return false;
	}
}

?>