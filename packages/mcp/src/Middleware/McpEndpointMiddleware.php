<?php

namespace Quiote\Mcp\Middleware;

use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quiote\Context;
use Quiote\Http\ProblemDetails;
use Quiote\Mcp\McpConfig;
use Quiote\Mcp\McpServer;

/**
 * The Streamable-HTTP transport: matches the configured `mcp.path` (default
 * `/mcp`) -- plus, when `mcp.auth` is `'oauth2'`, a GET to the RFC 9728
 * well-known metadata path, since that also has to reach
 * {@see McpServer::handleHttp()} for the SDK's own
 * `ProtectedResourceMetadataMiddleware` (composed there) to serve it -- and
 * delegates everything else to the rest of the pipeline unchanged.
 * Registered by {@see \Quiote\Mcp\McpPlugin} *before* `SecurityMiddleware`
 * (MCP does its own auth, not session/CSRF), so it still inherits earlier
 * bootstrap middleware (tracing, payload parsing) but never reaches MVC
 * dispatch.
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

    /**
     * Serves the request from the MCP server when it targets the MCP endpoint.
     *
     * Delegates to the rest of the pipeline unless MCP is enabled and the path
     * is either the configured `mcp.path` or — under `mcp.auth = 'oauth2'` —
     * a GET to the RFC 9728 protected-resource metadata path. The
     * {@see McpServer} is built once on first match from the named context's
     * container and reused. Any throwable escaping the server is converted to
     * a 500 problem-details response rather than propagating.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $config = McpConfig::fromConfig();
        $path = $request->getUri()->getPath();

        $matchesMcpPath = $path === $config->path;
        $matchesOauthMetadataPath = $config->auth === 'oauth2'
            && $request->getMethod() === 'GET'
            && $path === ProtectedResourceMetadata::DEFAULT_METADATA_PATH;

        if (!$config->enabled || !($matchesMcpPath || $matchesOauthMetadataPath)) {
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
