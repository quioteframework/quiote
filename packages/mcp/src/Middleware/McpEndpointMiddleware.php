<?php

namespace Quiote\Mcp\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quiote\Context;
use Quiote\Http\ProblemDetails;
use Quiote\Mcp\McpConfig;
use Quiote\Mcp\McpServer


/**
 * The Streamable-HTTP transport: matches the configured `mcp.path` (default
 * `/mcp`) and delegates everything else to the
 * rest of the pipeline unchanged. Registered by {@see \Quiote\Mcp\McpPlugin}
 * *before* `SecurityMiddleware` (MCP does its own auth, not session/CSRF), so
 * it still inherits earlier bootstrap middleware (tracing, payload parsing)
 * but never reaches MVC dispatch.
 *
 * Resolves the DI container from a single named {@see Context} (default
 * `core.default_context`) rather than "whichever context is handling this
 * request" -- same simplifying assumption `mcp:serve --context` makes -- since
 * a request only reaches this middleware once it's already inside that
 * context's own pipeline.
 */
final class McpEndpointMiddleware implements MiddlewareInterface
{
    private ?McpServer $server = null;

    public function __construct(private readonly string $contextName)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $config = McpConfig::fromConfig();

        if (!$config->enabled || $request->getUri()->getPath() !== $config->path) {
            return $handler->handle($request);
        }

        try {
            $server = $this->server ??= new McpServer(Context::getInstance($this->contextName)->getContainer(), $this->contextName);

            return $server->handleHttp($config, $request);
        } catch (\Throwable $e) {
            return $this->problemResponse($request, $e);
        }
    }

    private function problemResponse(ServerRequestInterface $request, \Throwable $e): ResponseInterface
    {
        $problem = ProblemDetails::create(
            status: 500,
            detail: "Internal server error",
            instance: (string) $request->getUri()->getPath(),
        );

        $factory = new \Nyholm\Psr7\Factory\Psr17Factory();

        return $factory->createResponse(500)
            ->withHeader('Content-Type', ProblemDetails::MEDIA_TYPE)
            ->withBody($factory->createStream($problem->toJson()));
    }
}
