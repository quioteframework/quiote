<?php

namespace Quiote\Mcp\Middleware;

use Quiote\Http\Psr17;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quiote\Context;
use Quiote\Http\ProblemDetails;
use Quiote\Mcp\Auth\McpAuthenticatorInterface;
use Quiote\Mcp\McpConfig;
use Quiote\Security\Auth\AuthorizationHeader;

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

    /**
     * Rejects requests to the MCP path that carry no valid bearer token.
     *
     * Passes the request straight down the pipeline when MCP is disabled, when
     * `mcp.auth` is `'none'`, or when the path is not the configured
     * `mcp.path`. Otherwise the `Authorization` header's Bearer credential is
     * handed to the container-resolved {@see McpAuthenticatorInterface}; a
     * missing, empty or rejected token yields a 401 problem-details response
     * carrying `WWW-Authenticate: Bearer`, and the inner handler is never
     * called.
     *
     * @throws \RuntimeException if the service registered for
     *         {@see McpAuthenticatorInterface} does not implement it
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $config = McpConfig::fromConfig();

        if (!$config->enabled || $config->auth === 'none' || $request->getUri()->getPath() !== $config->path) {
            return $handler->handle($request);
        }

        // Via AuthorizationHeader rather than str_starts_with($header, 'Bearer '):
        // RFC 9110 makes the scheme case-insensitive and the separator a run of
        // whitespace, so the literal-prefix test rejected the legal `bearer <tok>`
        // some clients and proxies emit, and a fixed-offset substr() left leading
        // whitespace on the token when more than one space was sent.
        $token = AuthorizationHeader::credential($request, 'Bearer');
        if ($token === null || $token === '') {
            return $this->unauthorized($request, 'Missing bearer token.');
        }

        if (!$this->authenticator()->authenticate($token)) {
            return $this->unauthorized($request, 'Invalid bearer token.');
        }

        return $handler->handle($request);
    }

    private function authenticator(): McpAuthenticatorInterface
    {
        if ($this->authenticator !== null) {
            return $this->authenticator;
        }

        $resolved = Context::getInstance($this->contextName)
            ->getContainer()
            ->get(McpAuthenticatorInterface::class);
        if (!$resolved instanceof McpAuthenticatorInterface) {
            // PSR-11 get() is typed mixed; a container registration that answers
            // this id with something else is a wiring bug, and saying so beats a
            // TypeError from the authenticate() call one line later.
            throw new \RuntimeException(sprintf(
                'The service registered for %s is a %s, which does not implement it.',
                McpAuthenticatorInterface::class,
                get_debug_type($resolved),
            ));
        }

        return $this->authenticator = $resolved;
    }

    private function unauthorized(ServerRequestInterface $request, string $detail): ResponseInterface
    {
        $problem = ProblemDetails::create(
            status: 401,
            detail: $detail,
            instance: (string) $request->getUri()->getPath(),
        );

        $factory = Psr17::factory();

        return $factory->createResponse(401)
            ->withHeader('Content-Type', ProblemDetails::MEDIA_TYPE)
            ->withHeader('WWW-Authenticate', 'Bearer')
            ->withBody($factory->createStream($problem->toJson()));
    }
}
