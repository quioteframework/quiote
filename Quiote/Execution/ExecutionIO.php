<?php
namespace Quiote\Execution;

use Quiote\Request\RequestDataHolder;
use Quiote\Response\WebResponse;

/**
 * Carries IO artifacts for an execution (request data + response + validation report placeholder).
 */
final class ExecutionIO
{
    public function __construct(
        public RequestDataHolder $requestData,
        public WebResponse $response
    ) {}
}
