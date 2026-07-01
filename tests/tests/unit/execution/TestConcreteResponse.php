<?php

use Quiote\Response\WebResponse;
use Quiote\Controller\OutputType;

class TestConcreteResponse extends WebResponse
{
    // Use WebResponse default implementation; provide minimal overrides where tests expect no-op initialization/send
    #[\Override]
    public function initialize($context = null, array $parameters = []) { /* no-op for test */ }
    #[\Override]
    public function send(?OutputType $outputType = null) { /* no-op for test */ }
}

?>