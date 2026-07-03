<?php

namespace Quiote\Mcp\Bridge;

use Mcp\Exception\ToolCallException;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ToolHandlerInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Quiote\Context;
use Symfony\Component\Routing\Generator\UrlGenerator;

/**
 * The actions-as-tools bridge (docs/MCP_SERVER_PLAN.md §7, the headline
 * feature): maps one `tools/call` to a specific `#[Route]` action's own
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
        $routing = $context->getRouting();

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

        $request = $this->buildRequest($path, $extraParams);

        try {
            $response = $context->handle($request);
        } catch (\Throwable $e) {
            throw new ToolCallException(sprintf('Action for route "%s" threw: %s', $this->routeName, $e->getMessage()), 0, $e);
        }

        $body = (string) $response->getBody();
        if ($response->getStatusCode() >= 400) {
            throw new ToolCallException(sprintf('Action for route "%s" returned HTTP %d: %s', $this->routeName, $response->getStatusCode(), $body));
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

        $factory = new Psr17Factory();

        return $request
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody($extraParams)
            ->withBody($factory->createStream(json_encode($extraParams, JSON_THROW_ON_ERROR)));
    }
}
