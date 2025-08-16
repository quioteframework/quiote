<?php

use Agavi\Routing\AgaviRoutingCallback;

class NonMatchingRoutingCallback extends AgaviRoutingCallback
{
	public function onMatched(array &$parameters, $legacyContainer = null)
	{
		return false;
	}
}

?>