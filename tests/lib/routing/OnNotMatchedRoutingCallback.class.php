<?php

use Quiote\Exception\QuioteException;
use Quiote\Routing\RoutingCallback;

class OnNotMatchedRoutingCallback extends RoutingCallback
{
	/**
	 * Gets executed when the route of this callback route did not match.
	 * @param      ExecutionContainer The original execution container.
	 * @since      1.0.0
	 */
	#[\Override]
    public function onNotMatched($legacyContainer = null)
	{
		throw new Exception('Not Matched');
		return;
	}
}

?>