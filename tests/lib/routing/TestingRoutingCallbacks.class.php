<?php
use Quiote\Routing\IRoutingCallback;
use Quiote\Routing\RoutingCallback;

/**
 * Mock routing callbacks for testing
 * @since      1.0.0
 * @version    1.0.0
 */
class TestMatchingRoutingCallback extends RoutingCallback
{
    #[\Override]
    public function onMatched(array &$parameters, $legacyContainer = null) { return true; }
}

class TestNonMatchingRoutingCallback extends RoutingCallback
{
    #[\Override]
    public function onMatched(array &$parameters, $legacyContainer = null) { return false; }
}

class TestOnNotMatchedRoutingCallback extends RoutingCallback
{
    #[\Override]
    public function onNotMatched($legacyContainer = null)
    {
        // Mark attribute directly via context routing callbacks pool if needed
        $context = $this->getContext();
        $request = $context->getContainer()->get(\Quiote\Request\WebRequest::class);
        $context->getContainer()->get(\Quiote\Request\RequestState::class)->publish($request->setAttribute('quiote.routing.callbacks.on_not_matched', true));
    }
}

class TestGenWithParamRoutingCallback extends RoutingCallback
{
    #[\Override]
    public function onGenerate(array $defaultParameters, array &$userParameters, array &$userOptions)
    {
        $userParameters['callback_param'] = 'added_by_callback';
        return true;
    }
}

class TestGenWithUnescapedParamRoutingCallback extends RoutingCallback
{
    #[\Override]
    public function onGenerate(array $defaultParameters, array &$userParameters, array &$userOptions)
    {
        $userParameters['callback_param'] = 'added/by/callback';
        $unescape = is_array($userOptions['quiote.routing.unescape'] ?? null) ? $userOptions['quiote.routing.unescape'] : [];
        $unescape[] = 'callback_param';
        $userOptions['quiote.routing.unescape'] = $unescape;
        return true;
    }
}

class TestGenUnsetRouteParamRoutingCallback extends RoutingCallback
{
    #[\Override]
    public function onGenerate(array $defaultParameters, array &$userParameters, array &$userOptions)
    {
        unset($userParameters['number']);
        return true;
    }
}

class TestGenUnsetExtraParamRoutingCallback extends RoutingCallback
{
    #[\Override]
    public function onGenerate(array $defaultParameters, array &$userParameters, array &$userOptions)
    {
        unset($userParameters['extra']);
        return true;
    }
}

class TestGenNullifyRouteParamRoutingCallback extends RoutingCallback
{
    #[\Override]
    public function onGenerate(array $defaultParameters, array &$userParameters, array &$userOptions)
    {
        $userParameters['number'] = null;
        return true;
    }
}

class TestGenNullifyExtraParamRoutingCallback extends RoutingCallback
{
    #[\Override]
    public function onGenerate(array $defaultParameters, array &$userParameters, array &$userOptions)
    {
        $userParameters['extra'] = null;
        return true;
    }
}

class TestGenSetPrefixAndPostfixRoutingCallback extends RoutingCallback
{
    #[\Override]
    public function onGenerate(array $defaultParameters, array &$userParameters, array &$userOptions)
    {
        $userOptions['prefix'] = 'prefix/';
        $userOptions['postfix'] = '/postfix';
        return true;
    }
}

class TestGenSetPrefixAndPostfixIntoRouteRoutingCallback extends RoutingCallback
{
    #[\Override]
    public function onGenerate(array $defaultParameters, array &$userParameters, array &$userOptions)
    {
        if (isset($userParameters['number']) && (is_string($userParameters['number']) || is_int($userParameters['number']) || is_float($userParameters['number']))) {
            $userParameters['number'] = 'prefix/' . $userParameters['number'] . '/postfix';
        }
        return true;
    }
}
