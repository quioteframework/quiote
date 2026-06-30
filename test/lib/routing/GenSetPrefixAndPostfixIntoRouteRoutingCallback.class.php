<?php

use Agavi\Routing\AgaviRoutingCallback;

class GenSetPrefixAndPostfixIntoRouteRoutingCallback extends AgaviRoutingCallback
{
	#[\Override]
    public function onGenerate(array $defaultParameters, array &$userParameters, array &$userOptions)
	{
		$this->route['opt']['defaults']['number'] = ['pre' => 'prefix-', 'val' => 'value', 'post' => '-postfix'];
		return true;
	}
}

?>