<?php

use Agavi\Routing\AgaviRoutingCallback;

class MatchingRoutingCallback extends AgaviRoutingCallback
{
	#[\Override]
    public function onMatched(array &$parameters, $legacyContainer = null)
	{
		$parameters['callback'] = 'set';
		return true;
	}
}

?>