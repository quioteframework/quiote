<?php

namespace Quiote\Mcp\Middleware;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quiote\Context;
use Quiote\Http\ProblemDetails;
use Quiote\Mcp\Auth\McpAuthenticatorInterface;
use Quiote\Mcp\McpConfig;

/**
 * Bearer-token auth for the MCP HTTP endpoint. Registered by
 * {@see \Quiote\Mcp\McpPlugin} immediately *before*
 * {@see McpEndpointMiddleware} -- only when the "http" transport is enabled
 * and `mcp.auth` isn't `'none'` -- so an invalid/missing token never reaches
 * the SDK server at all. The actual validation is delegated to a
 * {@see McpAuthenticatorInterface} resolved from the DI container (default:
 * {@see \Quiote\Mcp\Auth\StaticTokenAuthenticator}), so an app can swap in its
 * own credential store via `PluginRegistrar::service()`.
 */
final class McpAuthMiddleware implements MiddlewareInterface
{
    private ?McpAuthenticatorInterface $authenticator = null;

    public function __construct(private readonly string $contextName)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $config = McpConfig::fromConfig();

        if (!$config->enabled || $config->auth === 'none' || $request->getUri()->getPath() !== $config->path) {
            return $handler->handle($request);
        }

        $header = $request->getHeaderLine('Authorization');
        if (!str_starts_with($header, 'Bearer ')) {
            return $this->unauthorized($request, 'Missing bearer token.');
        }

        $token = substr($header, strlen('Bearer '));
        if (!$this->authenticator()->authenticate($token)) {
            return $this->unauthorized($request, 'Invalid bearer token.');
        }

        return $handler->handle($request);
    }

    private function authenticator(): McpAuthenticatorInterface
    {
        return $this->authenticator ??= Context::getInstance($this->contextName)
            ->getContainer()
            ->get(McpAuthenticatorInterface::class);
    }

    private function unauthorized(ServerRequestInterface $request, string $detail): ResponseInterface
    {
        $problem = ProblemDetails::create(
            status: 401,
            detail: $detail,
            instance: (string) $request->getUri()->getPath(),
        );

        $factory = new Psr17Factory();

        return $factory->createResponse(401)
            ->withHeader('Content-Type', ProblemDetails::MEDIA_TYPE)
            ->withHeader('WWW-Authenticate', 'Bearer')
            ->withBody($factory->createStream($problem->toJson()));
    }
}
