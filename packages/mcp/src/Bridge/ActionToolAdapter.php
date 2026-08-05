<?php

namespace Quiote\Mcp\Bridge;

use Mcp\Exception\ToolCallException;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ToolHandlerInterface;
use Quiote\Http\Psr17;
use Nyholm\Psr7\ServerRequest;
use Quiote\Context;
use Symfony\Component\Routing\Generator\UrlGenerator;

/**
 * The actions-as-tools bridge (the headline feature): maps one `tools/call`
 * to a specific `#[Route]` action's own
 * execution path. Rather than reaching into `ActionExecutor` directly --
 * which requires preconditions (a canonical WebRequest, a validation
 * decision) that only `Context::handle()`'s own middleware pipeline
 * satisfies -- this builds a synthetic PSR-7 request and drives it through
 * that exact same pipeline, so the action gets the real DI, verb dispatch,
 * and validation a normal HTTP call would get, for free.
 *
 * One instance is registered per discovered action-tool (see
 * {@see \Quiote\Mcp\Compiler\ActionToolScanner}), each bound to its own
 * route name and primary HTTP method at construction time -- unlike a
 * `[class, method]` catalog handler, which mcp/sdk always re-resolves fresh
 * per call and so can't carry per-registration configuration like this.
 */
final class ActionToolAdapter implements ToolHandlerInterface
{
    public function __construct(
        private readonly string $contextName,
        private readonly string $routeName,
        private readonly string $httpMethod,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function execute(array $arguments, ClientGateway $gateway): mixed
    {
        $context = Context::getInstance($this->contextName);
        $routing = $context->getContainer()->get(\Quiote\Routing\Routing::class);

        $route = $routing->getRouteCollection()->get($this->routeName);
        if ($route === null) {
            throw new ToolCallException(sprintf('Route "%s" is no longer registered.', $this->routeName));
        }

        $pathVariables = array_flip($route->compile()->getPathVariables());
        $pathParams = array_intersect_key($arguments, $pathVariables);
        $extraParams = array_diff_key($arguments, $pathVariables);

        $generator = new UrlGenerator($routing->getRouteCollection(), $routing->getRequestContext());
        try {
            $path = $generator->generate($this->routeName, $pathParams, UrlGenerator::ABSOLUTE_PATH);
        } catch (\Throwable $e) {
            throw new ToolCallException(sprintf('Could not build a request for route "%s": %s', $this->routeName, $e->getMessage()));
        }

        // Attached before dispatch rather than read back afterwards: the pipeline
        // reuses an ExecutionState it finds on the request instead of building its
        // own, and mutates it in place. That makes this our window into what the
        // pipeline decided -- the inner request object itself never comes back to us.
        $executionState = new \Quiote\Execution\ExecutionState();
        $request = $this->buildRequest($path, $extraParams)
            ->withAttribute(\Quiote\Execution\ExecutionState::class, $executionState);

        try {
            $response = $context->getRequestHandler()->handle($request);
        } catch (\Throwable $e) {
            throw new ToolCallException(sprintf('Action for route "%s" threw: %s', $this->routeName, $e->getMessage()), 0, $e);
        }

        $body = (string) $response->getBody();
        if ($response->getStatusCode() >= 400) {
            throw new ToolCallException(sprintf('Action for route "%s" returned HTTP %d: %s', $this->routeName, $response->getStatusCode(), $body));
        }

        // A security forward renders the login/secure system action and returns
        // HTTP 200, so status alone cannot distinguish "the action ran" from "the
        // action was denied and you are looking at a login page". Returning that
        // body as the tool result told the calling model the call succeeded and
        // handed it markup it may well act on.
        if ($executionState->forwarded) {
            // Deliberately not reporting ExecutionState::$securityDecision here: on a
            // forward SecurityMiddleware resets it to Allow for the *forwarded* action,
            // so quoting it would report "Allow" for a request that was in fact denied.
            // The action actually reached (login/secure) is the informative part.
            throw new ToolCallException(sprintf(
                'Action for route "%s" did not run: the request was forwarded to the "%s" system action. '
                . 'An MCP tool call carries no session or interactive credential, so an action requiring '
                . 'one cannot be invoked this way.',
                $this->routeName,
                (string) ($executionState->action ?? 'unknown'),
            ));
        }

        return $body;
    }

    /** @param array<string, mixed> $extraParams */
    private function buildRequest(string $path, array $extraParams): ServerRequest
    {
        $method = strtoupper($this->httpMethod);
        $request = new ServerRequest($method, $path);

        if ($method === 'GET' || $method === 'HEAD') {
            if ($extraParams !== []) {
                $request = $request->withUri($request->getUri()->withQuery(http_build_query($extraParams)));
            }

            return $request->withQueryParams($extraParams);
        }

        $factory = Psr17::factory();

        return $request
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody($extraParams)
            ->withBody($factory->createStream(json_encode($extraParams, JSON_THROW_ON_ERROR)));
    }
}
