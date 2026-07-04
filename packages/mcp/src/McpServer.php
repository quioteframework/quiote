<?php

namespace Quiote\Mcp;

use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Tool;
use Mcp\Server as SdkServer;
use Mcp\Server\Transport\StdioTransport;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Quiote\Context;
use Quiote\DI\Container;
use Quiote\Exception\QuioteException;
use Quiote\Mcp\Bridge\ActionToolAdapter;
use Quiote\Mcp\Bridge\ContainerAdapter;
use Quiote\Mcp\Compiler\ActionToolScanner;

/**
 * Our own facade over the official `mcp/sdk` `Server::builder()` API (decision
 * §2.1 / §4 of docs/MCP_SERVER_PLAN.md): every `Mcp\*` symbol the app touches
 * is confined to this class (plus {@see Bridge\ContainerAdapter}), so an SDK
 * breaking change (it is pre-1.0) touches one file, not the whole feature.
 *
 * Builds an SDK server from {@see McpCatalog}'s registered tools/resources/
 * prompts, resolving each handler through Quiote's own DI {@see Container}.
 */
final class McpServer
{
    private ?SdkServer $server = null;

    public function __construct(private readonly Container $container, private readonly string $contextName) {}

    /** Assemble (and cache) the SDK server from the current {@see McpCatalog} contents. */
    public function build(McpConfig $config): SdkServer
    {
        if ($this->server !== null) {
            return $this->server;
        }

        $this->requireSdk();

        $builder = SdkServer::builder()
            ->setServerInfo($config->serverName, $config->serverVersion)
            ->setContainer(new ContainerAdapter($this->container))
            ->setProtocolVersion(ProtocolVersion::from($config->protocolVersion));

        foreach (McpCatalog::tools() as $tool) {
            $builder->addTool(
                handler: $tool['handler'],
                name: $tool['name'],
                title: $tool['title'],
                description: $tool['description'],
                inputSchema: $tool['inputSchema'],
                outputSchema: $tool['outputSchema'],
            );
        }

        foreach (McpCatalog::resources() as $resource) {
            $builder->addResource(
                handler: $resource['handler'],
                uri: $resource['uri'],
                name: $resource['name'],
                title: $resource['title'],
                description: $resource['description'],
                mimeType: $resource['mimeType'],
            );
        }

        foreach (McpCatalog::prompts() as $prompt) {
            $builder->addPrompt(
                handler: $prompt['handler'],
                name: $prompt['name'],
                title: $prompt['title'],
                description: $prompt['description'],
            );
        }

        if ($config->exposeActions) {
            $this->addActionTools($builder, $config);
        }

        return $this->server = $builder->build();
    }

    /**
     * The actions-as-tools bridge (docs/MCP_SERVER_PLAN.md §7): discovers
     * `#[Route]` actions also carrying `#[McpTool]` and registers each as an
     * explicit tool (`Builder::add()`, not `addTool()`) paired with an
     * {@see ActionToolAdapter} bound to that specific route -- the "runtime-known
     * definition + handler pair" entry point the SDK's own docblock calls out
     * for exactly this kind of dynamically-generated registration.
     *
     * Input schema is deliberately permissive (docs/MCP_SERVER_PLAN.md §15
     * risk: "not every validator rule maps to JSON Schema") -- validation
     * still happens for real when the tool call is dispatched, via the same
     * pipeline (and hence the same validators) a normal HTTP request to that
     * route would go through.
     */
    private function addActionTools(\Mcp\Server\Builder $builder, McpConfig $config): void
    {
        $controller = Context::getInstance($this->contextName)->getController();
        $definitions = (new ActionToolScanner())->scan($controller, $config->moduleDirs ?: null);

        foreach ($definitions as $definition) {
            // Prefer the schema derived from the action's own validators
            // (docs/MCP_SERVER_PLAN.md §7); fall back to a permissive one when
            // none could be derived. Either way real enforcement happens on
            // dispatch, so the fallback loses precision, not safety.
            $inputSchema = $definition->inputSchema
                ?? ['type' => 'object', 'properties' => [], 'required' => [], 'additionalProperties' => true];

            // Tool::fromArray() (not the constructor) normalizes an empty
            // "properties" to a JSON object ({}) rather than an array ([]) --
            // the opis/json-schema validator CallToolHandler runs every call's
            // arguments through rejects the array form ("properties must be
            // an object").
            $tool = Tool::fromArray([
                'name' => $definition->toolName,
                'description' => $definition->description,
                'inputSchema' => $inputSchema,
                'outputSchema' => $definition->outputSchema,
            ]);

            $builder->add($tool, new ActionToolAdapter($this->contextName, $definition->routeName, $definition->httpMethod));
        }
    }

    /**
     * Run the stdio transport loop (blocks until the client disconnects or the
     * process is signalled). Returns the process exit code.
     */
    public function runStdio(McpConfig $config): int
    {
        return $this->build($config)->run(new StdioTransport());
    }

    /**
     * Drive one Streamable-HTTP request/response cycle (docs/MCP_SERVER_PLAN.md
     * §5.1). The SDK server is stateless per PHP request either way (no shared
     * state survives beyond this call other than what its session store
     * persists) so it's safe to reuse the cached {@see build()} result across
     * requests within a worker.
     *
     * `StreamableHttpTransport` reads the JSON-RPC payload by re-reading the
     * request's raw body stream itself -- but earlier in the real pipeline,
     * `PayloadParsingMiddleware` already consumed that stream to populate
     * `getParsedBody()` and does not rewind it, so by the time this runs the
     * stream is at EOF. Rebuilding the body from the already-parsed data (per
     * §5.1: "no re-parsing") avoids a spurious JSON parse error on every call.
     */
    public function handleHttp(McpConfig $config, ServerRequestInterface $request): ResponseInterface
    {
        $parsedBody = $request->getParsedBody();
        if (is_array($parsedBody)) {
            $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
            $request = $request->withBody($factory->createStream(json_encode($parsedBody, JSON_THROW_ON_ERROR)));
        }

        return $this->build($config)->run(new StreamableHttpTransport($request));
    }

    private function requireSdk(): void
    {
        if (!class_exists(SdkServer::class)) {
            throw new QuioteException(
                'The MCP server requires the "mcp/sdk" package, but "Mcp\\Server" was not found. '
                . 'Install it with: composer require mcp/sdk'
            );
        }
    }
}
