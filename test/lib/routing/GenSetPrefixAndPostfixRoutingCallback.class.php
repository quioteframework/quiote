<?php

use Agavi\Routing\AgaviRoutingCallback;

class GenSetPrefixAndPostfixRoutingCallback extends AgaviRoutingCallback
{
	#[\Override]
    public function onGenerate(array $defaultParameters, array &$userParameters, array &$userOptions)
	{
		$userParameters['number'] = $this->context->getRouting()->createValue('value')->setPrefix('prefix-')->setPostfix('-postfix');
		return true;
	}
}

?>