<?php

use Quiote\Routing\RoutingCallback;

class GenSetPrefixAndPostfixIntoRouteRoutingCallback extends RoutingCallback
{
	#[\Override]
    public function onGenerate(array $defaultParameters, array &$userParameters, array &$userOptions)
	{
		$this->route['opt']['defaults']['number'] = ['pre' => 'prefix-', 'val' => 'value', 'post' => '-postfix'];
		return true;
	}
}

?>