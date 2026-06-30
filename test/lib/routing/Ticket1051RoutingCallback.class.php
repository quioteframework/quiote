<?php

use Agavi\Routing\AgaviRoutingCallback;

class Ticket1051RoutingCallback extends AgaviRoutingCallback
{
	#[\Override]
    public function onGenerate(array $defaultParameters, array &$userParameters, array &$userOptions)
	{
		$userOptions['authority'] = 'www.agavi.org';
		
		return true;
	}
}

?>