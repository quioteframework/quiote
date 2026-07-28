<?php
declare(strict_types=1);

namespace Quiote\Openapi;

use Quiote\Action\Action;
use Quiote\Controller\Controller;
use Quiote\Execution\ActionResolver;
use Quiote\Execution\HttpMethodMapper;
use Quiote\Http\MimeTypeRegistry;
use Quiote\Http\ProblemDetails;
use Quiote\Routing\Compiler\RouteDefinition;
use Quiote\Support\Compiler\Diagnostic;
use Quiote\Validator\Compiler\JsonSchema\ActionInputSchemaResolver;
use ReflectionClass;

/**
 * Derives an OpenAPI 3.1 document from things the app already declares:
 * the routing IR says which paths and verbs exist and which action each
 * resolves to, each action's own validators say which parameters it accepts
 * and what they must look like (via
 * {@see \Quiote\Validator\Compiler\JsonSchema\ActionInputSchemaResolver}, the
 * same derivation that gives an MCP tool its `inputSchema`), the output type
 * says what media type a successful response carries, and
 * {@see ProblemDetails} says what a failure looks like. Nothing here is a
 * second, hand-maintained description of the API that can drift from it.
 *
 * Which request parameters land where follows what the pipeline actually
 * reads: a validator-described parameter whose name is a path placeholder
 * becomes a path parameter; on verbs that carry no body (GET/HEAD/DELETE/
 * OPTIONS/TRACE) the rest become query parameters; on the others they become a
 * requestBody, offered as both `application/json` and
 * `application/x-www-form-urlencoded` because
 * {@see \Quiote\Middleware\PayloadParsingMiddleware} parses both into the same
 * request parameters.
 *
 * Deliberate limits, so the document stays honest rather than looking more
 * complete than it is:
 *  - Response *bodies* aren't described. An action returns a view name and the
 *    view renders whatever it likes, so there is nothing to derive; the schema
 *    is left unconstrained and only the media type is stated.
 *  - An action without validators contributes an operation with no parameters
 *    beyond its path placeholders -- absence of a declaration is reported as
 *    absence of knowledge, not as "accepts nothing".
 *  - Symfony's optional path placeholders (`/list/{page?1}`) are emitted as
 *    required path parameters carrying that default, because OpenAPI has no
 *    optional path parameter at all.
 * @since      1.2.5
 */
final class OpenApiGenerator
{
    public const string OPENAPI_VERSION = '3.1.0';
    public const string CODE_DUPLICATE_OPERATION = 'DUPLICATE_OPENAPI_OPERATION';
    public const string PROBLEM_SCHEMA_NAME = 'ProblemDetails';

    /** Verbs whose parameters travel in the query string rather than a body. */
    private const array BODYLESS_VERBS = ['GET', 'HEAD', 'DELETE', 'OPTIONS', 'TRACE'];

    /** Verbs probed against an action when a route declares none (i.e. accepts all). */
    private const array PROBE_VERBS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /** @var Diagnostic[] */
    private array $diagnostics = [];

    private readonly ActionInputSchemaResolver $schemas;

    public function __construct(?ActionInputSchemaResolver $schemas = null)
    {
        $this->schemas = $schemas ?? new ActionInputSchemaResolver();
    }

    /**
     * @param RouteDefinition[] $routes Typically {@see \Quiote\Routing\Compiler\RouteCollectionIntrospector}'s
     *        view of the live route collection, so file-declared routes are
     *        described alongside `#[Route]`-declared ones.
     * @return array<string, mixed> The OpenAPI document, ready for json_encode()/Yaml::dump().
     */
    public function generate(array $routes, Controller $controller, ?OpenApiOptions $options = null): array
    {
        $options ??= OpenApiOptions::fromConfig();
        $this->diagnostics = [];

        /** @var array<string, array<string, array<string, mixed>>> $paths */
        $paths = [];
        $emittedProblemResponse = false;

        foreach ($routes as $route) {
            if (!$this->describes($route, $options)) {
                continue;
            }

            $template = RoutePathTemplate::parse($route->path);
            $action = $this->instantiate($controller, $route);

            $verbs = $this->verbsFor($route, $action);
            foreach ($verbs as $verb) {
                $key = strtolower($verb);
                if (isset($paths[$template->path][$key])) {
                    $this->diagnostics[] = new Diagnostic(
                        Diagnostic::SEVERITY_WARNING,
                        self::CODE_DUPLICATE_OPERATION,
                        sprintf(
                            'Route "%s" describes %s %s, which route "%s" already described; the first one wins in the document.',
                            $route->name,
                            $verb,
                            $template->path,
                            $this->routeNameOf($paths[$template->path][$key]),
                        ),
                        $route->sourceRef,
                        symbol: $route->name,
                    );
                    continue;
                }

                $schema = $this->schemas->resolve($controller, $route->module, $route->action, HttpMethodMapper::toActionMethod($verb));
                $paths[$template->path][$key] = $this->buildOperation($route, $template, $verb, count($verbs) > 1, $schema, $action, $controller, $options);
                // Every described operation gets at least the 500 problem
                // response, so one operation is enough to need the schema.
                $emittedProblemResponse = $emittedProblemResponse || $options->problemResponses;
            }
        }

        ksort($paths);

        $document = [
            'openapi' => self::OPENAPI_VERSION,
            'info' => $this->info($options),
        ];
        if ($options->servers !== []) {
            $document['servers'] = $options->servers;
        }
        $document['paths'] = $paths;
        if ($emittedProblemResponse) {
            $document['components'] = ['schemas' => [self::PROBLEM_SCHEMA_NAME => $this->problemDetailsSchema()]];
        }

        return $document;
    }

    /**
     * @return Diagnostic[] Diagnostics recorded during the last generate().
     */
    public function getDiagnostics(): array
    {
        return $this->diagnostics;
    }

    private function describes(RouteDefinition $route, OpenApiOptions $options): bool
    {
        // A route with no module/action resolves to no action at all (a bare
        // redirect entry, say) -- there is nothing to describe about it.
        return $route->module !== ''
            && $route->action !== ''
            && !$options->excludes($route->name)
            && $options->coversModule($route->module);
    }

    /** @return array<string, mixed> */
    private function info(OpenApiOptions $options): array
    {
        $info = ['title' => $options->title, 'version' => $options->version];
        if ($options->description !== null && $options->description !== '') {
            $info['description'] = $options->description;
        }

        return $info;
    }

    /**
     * The action instance is used only for reflection here -- which verbs it
     * implements, what its docblock says. It is never initialized or executed;
     * schema derivation instantiates its own throwaway copy per method token.
     */
    private function instantiate(Controller $controller, RouteDefinition $route): ?Action
    {
        try {
            return $controller->createActionInstance($route->module, $route->action);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The HTTP verbs to describe: those the route declares, or -- when it
     * declares none, meaning it accepts every verb -- those the action actually
     * implements an `execute*()` method for, resolved exactly the way dispatch
     * resolves them ({@see ActionResolver::resolveMethodName()}). An action that
     * only has a catch-all `execute()` (or nothing resolvable at all) is
     * described as GET.
     * @return list<string>
     */
    private function verbsFor(RouteDefinition $route, ?Action $action): array
    {
        if ($route->methods !== []) {
            $verbs = [];
            foreach ($route->methods as $method) {
                $verb = strtoupper($method);
                if ($verb !== '' && !in_array($verb, $verbs, true)) {
                    $verbs[] = $verb;
                }
            }
            if ($verbs !== []) {
                return $verbs;
            }
        }

        if ($action === null) {
            return ['GET'];
        }

        $verbs = [];
        foreach (self::PROBE_VERBS as $verb) {
            if (ActionResolver::resolveMethodName($action, $verb) !== null) {
                $verbs[] = $verb;
            }
        }

        return $verbs !== [] ? $verbs : ['GET'];
    }

    /**
     * @param bool $verbInOperationId Whether the route serves several verbs, each of which needs its own operation id.
     * @param array<string, mixed>|null $schema The validator-derived request schema, or null when the action declares no validators for this verb.
     * @return array<string, mixed>
     */
    private function buildOperation(
        RouteDefinition $route,
        RoutePathTemplate $template,
        string $verb,
        bool $verbInOperationId,
        ?array $schema,
        ?Action $action,
        Controller $controller,
        OpenApiOptions $options,
    ): array {
        $properties = $this->properties($schema);
        $required = $this->requiredNames($schema);

        $operation = [
            'operationId' => $this->operationId($route, $verb, $verbInOperationId),
        ];

        [$summary, $description] = $options->useActionDocblocks ? $this->documentation($action) : [null, null];
        if ($summary !== null) {
            $operation['summary'] = $summary;
        }
        if ($description !== null) {
            $operation['description'] = $description;
        }
        $operation['tags'] = [$route->module];

        $parameters = $this->pathParameters($route, $template, $properties);
        $leftovers = array_diff_key($properties, array_flip($template->variables));

        if (in_array($verb, self::BODYLESS_VERBS, true)) {
            foreach ($leftovers as $name => $propertySchema) {
                $parameters[] = [
                    'name' => $name,
                    'in' => 'query',
                    'required' => in_array($name, $required, true),
                    'schema' => $propertySchema === [] ? new \stdClass() : $propertySchema,
                ];
            }
        } elseif ($leftovers !== []) {
            $operation['requestBody'] = $this->requestBody($leftovers, $required);
        }

        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        $operation['responses'] = $this->responses($route, $schema, $controller, $options);
        $operation['x-quiote'] = $this->quioteExtension($route, $verb);

        return $operation;
    }

    /**
     * Path placeholders, described by their validator where one exists and by
     * the route's own requirement regex where it doesn't. Always `required`:
     * OpenAPI has no optional path parameter, so a Symfony placeholder with a
     * default carries that default on its schema instead.
     * @param array<string, array<string, mixed>> $properties
     * @return list<array<string, mixed>>
     */
    private function pathParameters(RouteDefinition $route, RoutePathTemplate $template, array $properties): array
    {
        $parameters = [];
        foreach ($template->variables as $name) {
            $schema = $properties[$name] ?? [];
            if ($schema === []) {
                $schema = ['type' => 'string'];
            }
            if (!array_key_exists('pattern', $schema) && !array_key_exists('enum', $schema)) {
                $requirement = $route->requirements[$name] ?? $template->requirements[$name] ?? null;
                if (is_string($requirement) && $requirement !== '') {
                    $schema['pattern'] = $requirement;
                }
            }

            $default = $template->defaults[$name] ?? $route->defaults[$name] ?? null;
            if (is_scalar($default) && !array_key_exists('default', $schema)) {
                $schema['default'] = $default;
            }

            $parameters[] = [
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => $schema,
            ];
        }

        return $parameters;
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     * @param list<string> $required
     * @return array<string, mixed>
     */
    private function requestBody(array $properties, array $required): array
    {
        $bodyRequired = array_values(array_intersect($required, array_keys($properties)));
        $schema = [
            'type' => 'object',
            'properties' => array_map(
                static fn(array $property): array|\stdClass => $property === [] ? new \stdClass() : $property,
                $properties,
            ),
        ];
        if ($bodyRequired !== []) {
            $schema['required'] = $bodyRequired;
        }
        // Kept permissive for the same reason the MCP inputSchema is: the
        // emitted schema describes the declared parameters, while the
        // validators remain the enforcement.
        $schema['additionalProperties'] = true;

        return [
            'required' => $bodyRequired !== [],
            'content' => [
                'application/json' => ['schema' => $schema],
                'application/x-www-form-urlencoded' => ['schema' => $schema],
            ],
        ];
    }

    /**
     * Keys are HTTP status codes. They are `int` rather than `string` because
     * PHP normalizes numeric array keys; both encoders still emit them as the
     * object member names OpenAPI wants.
     * @param array<string, mixed>|null $schema
     * @return array<int, array<string, mixed>>
     */
    private function responses(RouteDefinition $route, ?array $schema, Controller $controller, OpenApiOptions $options): array
    {
        $mediaType = $this->responseMediaType($controller, $route->outputType);
        $responses = [
            '200' => [
                'description' => 'Successful response',
                'content' => [$mediaType => ['schema' => $this->responseSchema($mediaType)]],
            ],
        ];

        if (!$options->problemResponses) {
            return $responses;
        }

        // Only routes whose action declares validators can fail validation, and
        // that failure is the 400 application/problem+json ValidationMiddleware
        // synthesizes -- see Quiote\Http\ProblemDetails.
        if ($schema !== null) {
            $responses['400'] = $this->problemResponse('The request failed validation; `errors` maps each field to its messages.');
        }
        $responses['500'] = $this->problemResponse('An unhandled error occurred while processing the request.');

        return $responses;
    }

    /** @return array<string, mixed> */
    private function problemResponse(string $description): array
    {
        return [
            'description' => $description,
            'content' => [
                ProblemDetails::MEDIA_TYPE => ['schema' => ['$ref' => '#/components/schemas/' . self::PROBLEM_SCHEMA_NAME]],
            ],
        ];
    }

    /**
     * A response body is whatever the action's view rendered, which no
     * declaration describes. Textual media types at least pin the JSON type;
     * anything else stays an unconstrained schema.
     * @return \stdClass|array<string, string>
     */
    private function responseSchema(string $mediaType): \stdClass|array
    {
        return str_starts_with($mediaType, 'text/') ? ['type' => 'string'] : new \stdClass();
    }

    /**
     * The media type a successful response carries, from the output type's own
     * `http_headers[Content-Type]` in output_types.* -- the same value
     * DispatchMiddleware sends -- falling back to
     * {@see MimeTypeRegistry::primaryMimeType()} for an output type that
     * declares no Content-Type. Charset and any other parameters are dropped:
     * an OpenAPI media type key is the bare type.
     */
    private function responseMediaType(Controller $controller, ?string $outputType): string
    {
        $mime = null;
        try {
            $configured = $controller->getOutputType($outputType !== '' ? $outputType : null)->getParameter('http_headers[Content-Type]');
            if (is_scalar($configured) && (string) $configured !== '') {
                $mime = (string) $configured;
            }
        } catch (\Throwable) {
        }

        if ($mime === null && $outputType !== null && $outputType !== '') {
            $mime = MimeTypeRegistry::primaryMimeType($outputType);
        }
        $mime ??= 'text/html';

        $semicolon = strpos($mime, ';');

        return strtolower(trim($semicolon === false ? $mime : substr($mime, 0, $semicolon)));
    }

    /**
     * The RFC 9457 document {@see ProblemDetails} emits, described once and
     * referenced by every error response.
     * @return array<string, mixed>
     */
    private function problemDetailsSchema(): array
    {
        return [
            'type' => 'object',
            'description' => 'RFC 9457 Problem Details document.',
            'properties' => [
                'type' => ['type' => 'string', 'format' => 'uri-reference', 'default' => 'about:blank'],
                'title' => ['type' => 'string'],
                'status' => ['type' => 'integer'],
                'detail' => ['type' => 'string'],
                'instance' => ['type' => 'string', 'format' => 'uri-reference'],
                'errors' => [
                    'type' => 'object',
                    'description' => 'Extension member: field name to its messages; the empty key holds non-field messages.',
                    'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
            ],
            'required' => ['type', 'title', 'status'],
            'additionalProperties' => true,
        ];
    }

    /**
     * Operation ids must be unique across the document, and a Quiote route can
     * serve several verbs from one action, so the verb is appended whenever
     * more than one operation is being described for the same route.
     */
    private function operationId(RouteDefinition $route, string $verb, bool $verbInOperationId): string
    {
        $base = $route->name !== '' ? $route->name : $route->module . '.' . $route->action;

        return $verbInOperationId ? $base . '.' . strtolower($verb) : $base;
    }

    /**
     * Where a route came from, for a reader (or a tool) that wants to find the
     * code behind an operation. `x-` members are the sanctioned place for this;
     * a spec consumer that doesn't care ignores them.
     * @return array<string, mixed>
     */
    private function quioteExtension(RouteDefinition $route, string $verb): array
    {
        $extension = [
            'route' => $route->name,
            'module' => $route->module,
            'action' => $route->action,
            'actionMethod' => 'execute' . ucfirst(HttpMethodMapper::toActionMethod($verb)),
        ];
        if ($route->outputType !== null && $route->outputType !== '') {
            $extension['outputType'] = $route->outputType;
        }
        if ($route->host !== null) {
            $extension['host'] = $route->host;
        }
        if ($route->condition !== null) {
            $extension['condition'] = $route->condition;
        }

        return $extension;
    }

    /**
     * Summary and description from the action class's docblock: its first
     * sentence and, if there is more prose before the first tag, the rest.
     * @return array{0: ?string, 1: ?string}
     */
    private function documentation(?Action $action): array
    {
        if ($action === null) {
            return [null, null];
        }
        $docComment = (new ReflectionClass($action))->getDocComment();
        if ($docComment === false) {
            return [null, null];
        }

        $lines = [];
        foreach (preg_split('/\R/', $docComment) ?: [] as $line) {
            $line = trim($line);
            $line = preg_replace('#^/\*\*+|\*+/$|^\*\s?#', '', $line) ?? $line;
            $line = trim($line);
            if (str_starts_with($line, '@')) {
                break;
            }
            $lines[] = $line;
        }

        // Rewrap: a docblock's line breaks are its author's column width, not
        // structure, so lines within a paragraph join with a space and only
        // blank lines survive as paragraph breaks.
        $paragraphs = [];
        $current = [];
        foreach ($lines as $line) {
            if ($line === '') {
                if ($current !== []) {
                    $paragraphs[] = implode(' ', $current);
                    $current = [];
                }
                continue;
            }
            $current[] = $line;
        }
        if ($current !== []) {
            $paragraphs[] = implode(' ', $current);
        }

        $prose = trim(implode("\n\n", $paragraphs));
        if ($prose === '') {
            return [null, null];
        }

        // First sentence = summary. A '. ' or a newline ends it; an abbreviation
        // mid-sentence would end it too, which is a cosmetic price for not
        // needing a sentence tokenizer here.
        if (preg_match('/^(.+?[.!?])(\s|$)/s', $prose, $matches) === 1) {
            $summary = trim(str_replace("\n", ' ', $matches[1]));
            $rest = trim(substr($prose, strlen($matches[1])));
        } else {
            $summary = trim(str_replace("\n", ' ', $prose));
            $rest = '';
        }

        return [$summary !== '' ? $summary : null, $rest !== '' ? $rest : null];
    }

    /**
     * @param array<string, mixed>|null $schema
     * @return array<string, array<string, mixed>>
     */
    private function properties(?array $schema): array
    {
        $properties = $schema['properties'] ?? null;
        if (!is_array($properties)) {
            return [];
        }

        $typed = [];
        foreach ($properties as $name => $property) {
            if (is_string($name) && $name !== '' && is_array($property)) {
                $typed[$name] = $property;
            }
        }

        return $typed;
    }

    /**
     * @param array<string, mixed>|null $schema
     * @return list<string>
     */
    private function requiredNames(?array $schema): array
    {
        $required = $schema['required'] ?? null;
        if (!is_array($required)) {
            return [];
        }

        $names = [];
        foreach ($required as $name) {
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /** @param array<string, mixed> $operation */
    private function routeNameOf(array $operation): string
    {
        $extension = $operation['x-quiote'] ?? null;
        $name = is_array($extension) ? ($extension['route'] ?? null) : null;

        return is_string($name) ? $name : '(unknown)';
    }
}
