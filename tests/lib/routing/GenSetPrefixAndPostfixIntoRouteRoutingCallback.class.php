<?php

use Quiote\Routing\RoutingCallback;

class GenSetPrefixAndPostfixIntoRouteRoutingCallback extends RoutingCallback
{
	#[\Override]
    public function onGenerate(array $defaultParameters, array &$userParameters, array &$userOptions)
	{
		if ($this->route === null) {
			throw new \RuntimeException('GenSetPrefixAndPostfixIntoRouteRoutingCallback used before initialize().');
		}
		$opt = is_array($this->route['opt'] ?? null) ? $this->route['opt'] : [];
		$defaults = is_array($opt['defaults'] ?? null) ? $opt['defaults'] : [];
		$defaults['number'] = ['pre' => 'prefix-', 'val' => 'value', 'post' => '-postfix'];
		$opt['defaults'] = $defaults;
		$this->route['opt'] = $opt;
		return true;
	}
}

?>