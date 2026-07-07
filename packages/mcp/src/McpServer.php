<?php

namespace Quiote\Mcp;

use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Tool;
use Mcp\Server as SdkServer;
use Mcp\Server\Transport\StdioTransport;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\SimpleCache\CacheInterface;
use Quiote\Config\Config;
use Quiote\Context;
use Quiote\DI\Container;
use Quiote\Exception\QuioteException;
use Quiote\Mcp\Bridge\ActionToolAdapter;
use Quiote\Mcp\Bridge\ContainerAdapter;
use Quiote\Mcp\Compiler\ActionToolScanner;
use Quiote\Mcp\Compiler\McpDirectoryResolver;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;

/**
 * Our own facade over the official `mcp/sdk` `Server::builder()` API: every
 * `Mcp\*` symbol the app touches is confined to this class (plus
 * {@see Bridge\ContainerAdapter}), so an SDK
 * breaking change (it is pre-1.0) touches one file, not the whole feature.
 *
 * Builds an SDK server from {@see McpCatalog}'s registered tools/resources/
 * prompts, resolving each handler through Quiote's own DI {@see Container}.
 *
 * @phpstan-import-type ToolInputSchema from Tool
 * @phpstan-import-type ToolOutputSchema from Tool
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

        $this->addAttributeDiscovery($builder, $config);

        return $this->server = $builder->build();
    }

    /**
     * Plain-class `#[McpTool]`/`#[McpResource]`/`#[McpPrompt]`/`#[McpResourceTemplate]`
     * discovery (as opposed to the actions-as-tools bridge, which only reads
     * `#[McpTool]` on classes that also carry `#[Route]`, see {@see addActionTools()}).
     * Delegates entirely to the SDK's own `Discoverer`/`DiscoveryLoader`
     * (`Builder::setDiscovery()`), scoped to each module's `Mcp/` subdirectory
     * (see {@see McpDirectoryResolver}) rather than the whole app, since that's
     * already where the actions-as-tools bridge and manual registration cover
     * the rest of a module's classes. A no-op when `mcp.discover_attributes`
     * is off (default) or no module contributes an `Mcp/` directory.
     */
    private function addAttributeDiscovery(\Mcp\Server\Builder $builder, McpConfig $config): void
    {
        if (!$config->discoverAttributes) {
            return;
        }

        $scanDirs = (new McpDirectoryResolver())->resolve($config->moduleDirs ?: null);
        if ($scanDirs === []) {
            return;
        }

        $builder->setDiscovery(
            basePath: '',
            scanDirs: $scanDirs,
            excludeDirs: [],
            cache: $this->buildDiscoveryCache($config),
        );
    }

    /**
     * A file-backed PSR-16 cache for discovery results, so a re-scan is only
     * paid once per module tree (until it changes) rather than on every
     * `McpServer::build()` -- including across separate PHP-FPM processes,
     * unlike an in-memory cache. `mcp:warmup` populates this ahead of time;
     * see {@see Console\McpWarmupCommand}. Returns null (SDK falls back to an
     * uncached `Discoverer`) when `mcp.discovery_cache` is off.
     */
    private function buildDiscoveryCache(McpConfig $config): ?CacheInterface
    {
        if (!$config->discoveryCache) {
            return null;
        }

        $cacheDir = rtrim(Config::getString('core.cache_dir', sys_get_temp_dir()), '/') . '/mcp-discovery';

        return new Psr16Cache(new FilesystemAdapter(namespace: '', defaultLifetime: 0, directory: $cacheDir));
    }

    /**
     * The actions-as-tools bridge: discovers `#[Route]` actions also
     * carrying `#[McpTool]` and registers each as an
     * explicit tool (`Builder::add()`, not `addTool()`) paired with an
     * {@see ActionToolAdapter} bound to that specific route -- the "runtime-known
     * definition + handler pair" entry point the SDK's own docblock calls out
     * for exactly this kind of dynamically-generated registration.
     *
     * Input schema is deliberately permissive (not every validator rule maps
     * to JSON Schema) -- validation still happens for real when the tool call
     * is dispatched, via the same
     * pipeline (and hence the same validators) a normal HTTP request to that
     * route would go through.
     */
    private function addActionTools(\Mcp\Server\Builder $builder, McpConfig $config): void
    {
        $controller = Context::getInstance($this->contextName)->getController();
        $definitions = (new ActionToolScanner())->scan($controller, $config->moduleDirs ?: null);

        foreach ($definitions as $definition) {
            $inputSchema = $this->buildToolInputSchema($definition->inputSchema);
            $outputSchema = $this->buildToolOutputSchema($definition->outputSchema);

            // Tool::fromArray() (not the constructor) normalizes an empty
            // "properties" to a JSON object ({}) rather than an array ([]) --
            // the opis/json-schema validator CallToolHandler runs every call's
            // arguments through rejects the array form ("properties must be
            // an object"). "outputSchema" is an optional key in ToolData, not
            // a nullable value, so it's only included when actually present.
            $tool = $outputSchema !== null
                ? Tool::fromArray([
                    'name' => $definition->toolName,
                    'description' => $definition->description,
                    'inputSchema' => $inputSchema,
                    'outputSchema' => $outputSchema,
                ])
                : Tool::fromArray([
                    'name' => $definition->toolName,
                    'description' => $definition->description,
                    'inputSchema' => $inputSchema,
                ]);

            $builder->add($tool, new ActionToolAdapter($this->contextName, $definition->routeName, $definition->httpMethod));
        }
    }

    /**
     * Normalizes an {@see \Quiote\Mcp\Compiler\ActionToolDefinition::$inputSchema}
     * (derived from the action's own validators, or null when none could be
     * derived) into the SDK's `ToolInputSchema` shape. Falls back to a fully
     * permissive schema -- real enforcement happens on dispatch either way, so
     * the fallback loses precision, not safety.
     *
     * Drops an incoming `additionalProperties` key rather than forwarding it:
     * `ToolInputSchema` doesn't carry one, but JSON Schema already treats a
     * missing key the same as `additionalProperties: true` -- and
     * `ValidatorSchemaMapper` (and this method's own fallback) never produce
     * `false` -- so omitting it changes nothing about how a `tools/call` is
     * actually validated.
     *
     * @param array<string, mixed>|null $schema
     * @return ToolInputSchema
     */
    private function buildToolInputSchema(?array $schema): array
    {
        return [
            'type' => 'object',
            'properties' => \is_array($schema['properties'] ?? null) ? $schema['properties'] : [],
            'required' => $this->normalizeRequiredList($schema['required'] ?? null),
        ];
    }

    /**
     * Normalizes an author-supplied `#[McpTool(outputSchema: ...)]` array into
     * the SDK's `ToolOutputSchema` shape. Unlike the input schema, nothing is
     * derived here -- the action author opted in explicitly -- so a
     * non-"object" `type` still throws, matching the check
     * `Tool::fromArray()` itself would otherwise have performed on the raw array.
     *
     * A nested-schema `additionalProperties` (as opposed to a plain bool) is
     * dropped rather than forwarded: preserving it would need to recursively
     * validate it's itself a well-formed JSON Schema, which is more machinery
     * than a rarely-used edge case of an already-optional, author-supplied
     * schema warrants -- the output schema just becomes correspondingly more
     * permissive by omission, the same graceful degradation
     * {@see \Quiote\Mcp\Compiler\ValidatorSchemaMapper} applies to unmappable
     * input validator rules.
     *
     * @param array<string, mixed>|null $schema
     * @return ToolOutputSchema|null
     */
    private function buildToolOutputSchema(?array $schema): ?array
    {
        if ($schema === null) {
            return null;
        }

        if (($schema['type'] ?? null) !== 'object') {
            throw new \Mcp\Exception\InvalidArgumentException('Tool outputSchema must be of type "object".');
        }

        $result = [
            'type' => 'object',
            'properties' => \is_array($schema['properties'] ?? null) ? $schema['properties'] : [],
            'required' => $this->normalizeRequiredList($schema['required'] ?? null),
        ];

        if (\is_bool($schema['additionalProperties'] ?? null)) {
            $result['additionalProperties'] = $schema['additionalProperties'];
        }
        if (\is_string($schema['description'] ?? null)) {
            $result['description'] = $schema['description'];
        }

        return $result;
    }

    /**
     * Never returns null: opis/json-schema (via {@see \Mcp\Capability\Discovery\SchemaValidator})
     * json_encode()s the whole schema verbatim and validates the result
     * against the JSON Schema meta-schema, which requires the "required"
     * keyword -- when present at all -- to be an array of strings. A `null`
     * here used to survive into the encoded schema as `"required":null`,
     * which fails that meta-schema check unconditionally, so *every*
     * `tools/call` against a tool whose schema had no derivable/declared
     * `required` list (the permissive fallback schema, or any legitimately
     * all-optional schema) failed with a `-32602` "Schema validation
     * process failed: required must be an array of strings" error before
     * the actual tool arguments were ever looked at. An empty array means
     * exactly the same thing to JSON Schema ("nothing is required") while
     * actually validating.
     * @return list<string>
     */
    private function normalizeRequiredList(mixed $required): array
    {
        if (!\is_array($required)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $entry): string => \is_scalar($entry) ? (string) $entry : '',
            $required,
        ));
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
     * Drive one Streamable-HTTP request/response cycle. The SDK server is
     * stateless per PHP request either way (no shared
     * state survives beyond this call other than what its session store
     * persists) so it's safe to reuse the cached {@see build()} result across
     * requests within a worker.
     *
     * `StreamableHttpTransport` reads the JSON-RPC payload by re-reading the
     * request's raw body stream itself -- but earlier in the real pipeline,
     * `PayloadParsingMiddleware` already consumed that stream to populate
     * `getParsedBody()` and does not rewind it, so by the time this runs the
     * stream is at EOF. Rebuilding the body from the already-parsed data
     * instead of re-parsing it avoids a spurious JSON parse error on every call.
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
