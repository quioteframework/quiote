<?php

use Quiote\Routing\RoutingCallback;

class Ticket1051RoutingCallback extends RoutingCallback
{
	#[\Override]
    public function onGenerate(array $defaultParameters, array &$userParameters, array &$userOptions)
	{
		$userOptions['authority'] = 'www.quiote.org';
		
		return true;
	}
}

?>