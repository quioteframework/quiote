<?php

use Quiote\Context;
use Quiote\Controller\Controller;
use Quiote\Openapi\OpenApiGenerator;
use Quiote\Openapi\OpenApiOptions;
use Quiote\Routing\Compiler\RouteCollectionIntrospector;
use Quiote\Routing\Compiler\RouteDefinition;
use Quiote\Support\Compiler\Diagnostic;
use Quiote\Testing\PhpUnitTestCase;

/**
 * OpenAPI generation from the routing IR plus the actions' own validators.
 *
 * Fixtures are the existing McpActionTool module (which the MCP
 * actions-as-tools tests also use, deliberately: the same validator
 * declarations feed both an MCP tool's inputSchema and an OpenAPI operation) --
 * McpActionTool.Greet declares a StringValidator(min=2,max=50) on `name` via
 * Validate/Greet.xml, McpActionTool.FluentValidator declares its own via
 * registerWriteValidators(), and McpActionTool.MultiVerb declares none while
 * implementing both executeRead() and executeWrite().
 */
final class OpenApiGeneratorTest extends PhpUnitTestCase
{
    private const string CONTEXT = 'mcp-action-tool-test';

    private function controller(): Controller
    {
        return Context::getInstance(self::CONTEXT)->getController();
    }

    /**
     * @param string[] $methods
     * @param array<string,string> $requirements
     * @param array<string,mixed> $defaults
     */
    private function route(
        string $name,
        string $path,
        string $module,
        string $action,
        array $methods = ['GET'],
        array $requirements = [],
        array $defaults = [],
        ?string $outputType = 'html',
    ): RouteDefinition {
        return new RouteDefinition(
            $name,
            $path,
            $module,
            $action,
            $methods,
            $defaults,
            $requirements,
            null,
            null,
            0,
            $outputType,
            ['gen_path' => $path, 'cut' => false, 'path' => $path],
            'OpenApiGeneratorTest',
        );
    }

    /**
     * @param RouteDefinition[] $routes
     * @return array<string, mixed>
     */
    private function generate(array $routes, ?OpenApiOptions $options = null, ?OpenApiGenerator $generator = null): array
    {
        $generator ??= new OpenApiGenerator();

        return $generator->generate($routes, $this->controller(), $options ?? new OpenApiOptions(title: 'Test API', version: '2.0.0'));
    }

    /**
     * Walks into the generated document, asserting each step exists and is
     * itself a map -- the document is `array<string, mixed>` by nature, so
     * every nested read has to prove its own shape.
     * @param array<mixed, mixed> $node
     * @return array<mixed, mixed>
     */
    private function at(array $node, string|int ...$path): array
    {
        $cursor = $node;
        foreach ($path as $key) {
            $this->assertArrayHasKey($key, $cursor, 'missing key: ' . $key);
            $next = $cursor[$key];
            $this->assertIsArray($next, 'not a map: ' . $key);
            $cursor = $next;
        }

        return $cursor;
    }

    /**
     * @param array<string, mixed> $document
     * @return list<string> The document's path templates, in document order.
     */
    private function pathNames(array $document): array
    {
        $names = [];
        foreach (array_keys($this->at($document, 'paths')) as $path) {
            $this->assertIsString($path);
            $names[] = $path;
        }

        return $names;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<mixed, mixed>
     */
    private function operation(array $document, string $path, string $verb): array
    {
        return $this->at($document, 'paths', $path, $verb);
    }

    public function testDocumentCarriesTheOpenApiVersionAndInfo(): void
    {
        $document = $this->generate(
            [$this->route('greet', '/greet/{name}', 'McpActionTool', 'Greet')],
            new OpenApiOptions(
                title: 'Test API',
                version: '2.0.0',
                description: 'Generated in a test.',
                servers: [['url' => 'https://api.example.test', 'description' => 'Production']],
            ),
        );

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertSame(['title' => 'Test API', 'version' => '2.0.0', 'description' => 'Generated in a test.'], $this->at($document, 'info'));
        $this->assertSame([['url' => 'https://api.example.test', 'description' => 'Production']], $this->at($document, 'servers'));
    }

    public function testServersAreOmittedWhenNoneAreConfigured(): void
    {
        $document = $this->generate([$this->route('greet', '/greet/{name}', 'McpActionTool', 'Greet')]);

        $this->assertArrayNotHasKey('servers', $document);
    }

    public function testAPathPlaceholderIsDescribedByItsOwnValidator(): void
    {
        $document = $this->generate([$this->route('greet', '/greet/{name}', 'McpActionTool', 'Greet')]);

        $this->assertSame([
            [
                'name' => 'name',
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 50],
            ],
        ], $this->at($this->operation($document, '/greet/{name}', 'get'), 'parameters'));
    }

    public function testAValidatedParameterThatIsNotInThePathBecomesAQueryParameterOnAReadVerb(): void
    {
        // Same action, routed at a path that does not capture `name`: the
        // validator-declared parameter has to arrive some other way, and on a
        // bodyless verb that means the query string.
        $document = $this->generate([$this->route('greet_query', '/greet', 'McpActionTool', 'Greet')]);

        $operation = $this->operation($document, '/greet', 'get');
        $this->assertSame([
            [
                'name' => 'name',
                'in' => 'query',
                'required' => true,
                'schema' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 50],
            ],
        ], $this->at($operation, 'parameters'));
        $this->assertArrayNotHasKey('requestBody', $operation);
    }

    public function testAWriteVerbTurnsValidatedParametersIntoARequestBodyInBothParsedMediaTypes(): void
    {
        $document = $this->generate([$this->route('fluent', '/fluent', 'McpActionTool', 'FluentValidator', ['POST'])]);

        $operation = $this->operation($document, '/fluent', 'post');
        $body = $this->at($operation, 'requestBody');
        $this->assertTrue($body['required'] ?? null);
        $expected = [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 20],
                'author_email' => ['type' => 'string', 'format' => 'email'],
            ],
            'required' => ['title'],
            'additionalProperties' => true,
        ];
        $this->assertSame($expected, $this->at($body, 'content', 'application/json', 'schema'));
        $this->assertSame($expected, $this->at($body, 'content', 'application/x-www-form-urlencoded', 'schema'));
        $this->assertArrayNotHasKey('parameters', $operation);
    }

    public function testNoRequestBodyWhenTheVerbHasNoValidatorsAtAll(): void
    {
        // FluentValidator registers its validators for the write token only;
        // asking for the update token (PUT) yields none, so there is nothing to
        // put in a body.
        $document = $this->generate([$this->route('fluent_put', '/fluent', 'McpActionTool', 'FluentValidator', ['PUT'])]);

        $this->assertArrayNotHasKey('requestBody', $this->operation($document, '/fluent', 'put'));
    }

    public function testAPathPlaceholderWithoutAValidatorFallsBackToTheRouteRequirement(): void
    {
        $document = $this->generate([
            $this->route('multi', '/multi/{id}', 'McpActionTool', 'MultiVerb', ['GET'], ['id' => '\d+']),
        ]);

        $this->assertSame([
            ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'pattern' => '\d+']],
        ], $this->at($this->operation($document, '/multi/{id}', 'get'), 'parameters'));
    }

    public function testAnInlineRequirementAndDefaultSurviveOntoTheParameterSchema(): void
    {
        $document = $this->generate([
            $this->route('multi_page', '/multi/{page<\d+>?1}', 'McpActionTool', 'MultiVerb'),
        ]);

        // OpenAPI has no optional path parameter, so the default rides along on
        // the schema and the parameter stays required.
        $this->assertSame([
            ['name' => 'page', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'pattern' => '\d+', 'default' => '1']],
        ], $this->at($this->operation($document, '/multi/{page}', 'get'), 'parameters'));
    }

    public function testEveryDeclaredVerbGetsItsOwnOperationWithADistinctOperationId(): void
    {
        $document = $this->generate([
            $this->route('multi', '/multi', 'McpActionTool', 'MultiVerb', ['GET', 'POST']),
        ]);

        $this->assertSame(['get', 'post'], array_keys($this->at($document, 'paths', '/multi')));
        $this->assertSame('multi.get', $this->operation($document, '/multi', 'get')['operationId'] ?? null);
        $this->assertSame('multi.post', $this->operation($document, '/multi', 'post')['operationId'] ?? null);
    }

    public function testASingleVerbRouteUsesItsRouteNameAsTheOperationId(): void
    {
        $document = $this->generate([$this->route('greet', '/greet/{name}', 'McpActionTool', 'Greet')]);

        $this->assertSame('greet', $this->operation($document, '/greet/{name}', 'get')['operationId'] ?? null);
    }

    public function testARouteDeclaringNoVerbsIsDescribedByTheVerbsItsActionImplements(): void
    {
        // MultiVerbAction implements executeRead() and executeWrite() only.
        $document = $this->generate([$this->route('multi_any', '/multi-any', 'McpActionTool', 'MultiVerb', [])]);

        $this->assertSame(['get', 'post'], array_keys($this->at($document, 'paths', '/multi-any')));
    }

    public function testAnUnresolvableActionIsStillDescribedAsAGetOperation(): void
    {
        // No such action class: nothing can be derived, but the route exists and
        // the document should say so rather than silently omit it.
        $document = $this->generate([$this->route('ghost', '/ghost', 'NoSuchModule', 'NoSuchAction', [])]);

        $operation = $this->operation($document, '/ghost', 'get');
        $this->assertSame('ghost', $operation['operationId'] ?? null);
        $this->assertArrayNotHasKey('parameters', $operation);
        $this->assertArrayNotHasKey('requestBody', $operation);
        $this->assertArrayNotHasKey('summary', $operation);
    }

    public function testARouteWithoutAModuleOrActionIsSkipped(): void
    {
        $document = $this->generate([
            $this->route('bare', '/bare', '', ''),
            $this->route('greet', '/greet/{name}', 'McpActionTool', 'Greet'),
        ]);

        $this->assertSame(['/greet/{name}'], $this->pathNames($document));
    }

    public function testTheSuccessResponseUsesTheOutputTypesConfiguredContentType(): void
    {
        $document = $this->generate([
            $this->route('greet_json', '/greet.json', 'McpActionTool', 'Greet', ['GET'], [], [], 'json'),
        ]);

        $content = $this->at($this->operation($document, '/greet.json', 'get'), 'responses', 200, 'content');
        // output_types.xml declares application/json; charset=UTF-8 for `json`;
        // the charset is not part of an OpenAPI media type key.
        $this->assertSame(['application/json'], array_keys($content));
        // No declaration describes what the view rendered, so the schema stays
        // unconstrained rather than claiming a shape.
        $schema = $this->at($content, 'application/json')['schema'] ?? null;
        $this->assertInstanceOf(stdClass::class, $schema);
        $this->assertSame([], (array) $schema);
    }

    public function testAnHtmlResponseIsDescribedAsAString(): void
    {
        $document = $this->generate([$this->route('greet', '/greet/{name}', 'McpActionTool', 'Greet')]);

        $this->assertSame(
            ['text/html' => ['schema' => ['type' => 'string']]],
            $this->at($this->operation($document, '/greet/{name}', 'get'), 'responses', 200, 'content'),
        );
    }

    public function testValidatedRoutesGetTheProblemDetailsValidationResponseAndSchema(): void
    {
        $document = $this->generate([$this->route('greet', '/greet/{name}', 'McpActionTool', 'Greet')]);

        $responses = $this->at($this->operation($document, '/greet/{name}', 'get'), 'responses');
        $expected = ['application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/ProblemDetails']]];
        $this->assertSame($expected, $this->at($responses, 400, 'content'));
        $this->assertSame($expected, $this->at($responses, 500, 'content'));

        $schema = $this->at($document, 'components', 'schemas', 'ProblemDetails');
        $this->assertSame(['type', 'title', 'status'], $this->at($schema, 'required'));
        $this->assertArrayHasKey('errors', $this->at($schema, 'properties'));
    }

    public function testAnActionWithoutValidatorsGetsNoValidationResponse(): void
    {
        $document = $this->generate([$this->route('multi', '/multi', 'McpActionTool', 'MultiVerb', ['GET'])]);

        $responses = $this->at($this->operation($document, '/multi', 'get'), 'responses');
        $this->assertArrayNotHasKey(400, $responses);
        $this->assertArrayHasKey(500, $responses);
    }

    public function testProblemResponsesAndTheirSchemaCanBeTurnedOff(): void
    {
        $document = $this->generate(
            [$this->route('greet', '/greet/{name}', 'McpActionTool', 'Greet')],
            new OpenApiOptions(problemResponses: false),
        );

        $this->assertSame([200], array_keys($this->at($this->operation($document, '/greet/{name}', 'get'), 'responses')));
        $this->assertArrayNotHasKey('components', $document);
    }

    public function testTheActionDocblockBecomesTheSummaryAndDescription(): void
    {
        $document = $this->generate([$this->route('fluent', '/fluent', 'McpActionTool', 'FluentValidator', ['POST'])]);

        $operation = $this->operation($document, '/fluent', 'post');
        $summary = $operation['summary'] ?? null;
        $this->assertIsString($summary);
        $this->assertStringStartsWith("Regression fixture for ActionToolScanner's fluent-ValidatorBuilder", $summary);
        $this->assertStringEndsWith('.', $summary);
        $description = $operation['description'] ?? null;
        $this->assertIsString($description);
        // Paragraph prose keeps flowing as one line -- a docblock's line breaks
        // are its column width, not structure.
        $this->assertStringNotContainsString("\n", $description);
    }

    public function testDocblockDerivedProseCanBeTurnedOff(): void
    {
        $document = $this->generate(
            [$this->route('fluent', '/fluent', 'McpActionTool', 'FluentValidator', ['POST'])],
            new OpenApiOptions(useActionDocblocks: false),
        );

        $operation = $this->operation($document, '/fluent', 'post');
        $this->assertArrayNotHasKey('summary', $operation);
        $this->assertArrayNotHasKey('description', $operation);
    }

    public function testEachOperationRecordsWhereItCameFrom(): void
    {
        $document = $this->generate([$this->route('greet', '/greet/{name}', 'McpActionTool', 'Greet')]);

        $this->assertSame([
            'route' => 'greet',
            'module' => 'McpActionTool',
            'action' => 'Greet',
            'actionMethod' => 'executeRead',
            'outputType' => 'html',
        ], $this->at($this->operation($document, '/greet/{name}', 'get'), 'x-quiote'));
    }

    public function testTagsGroupOperationsByModule(): void
    {
        $document = $this->generate([$this->route('greet', '/greet/{name}', 'McpActionTool', 'Greet')]);

        $this->assertSame(['McpActionTool'], $this->at($this->operation($document, '/greet/{name}', 'get'), 'tags'));
    }

    public function testAnExcludedRouteNamePatternIsLeftOut(): void
    {
        $document = $this->generate(
            [
                $this->route('internal.greet', '/internal/greet/{name}', 'McpActionTool', 'Greet'),
                $this->route('public.multi', '/public/multi', 'McpActionTool', 'MultiVerb'),
            ],
            new OpenApiOptions(excludeRoutes: ['internal.*']),
        );

        $this->assertSame(['/public/multi'], $this->pathNames($document));
    }

    public function testAModuleFilterLeavesOtherModulesOut(): void
    {
        $document = $this->generate(
            [
                $this->route('greet', '/greet/{name}', 'McpActionTool', 'Greet'),
                $this->route('attr_list', '/attr-routing', 'AttrRouting', 'List'),
            ],
            new OpenApiOptions(modules: ['attrrouting']),
        );

        $this->assertSame(['/attr-routing'], $this->pathNames($document));
    }

    public function testASecondRouteForTheSamePathAndVerbIsReportedAndTheFirstWins(): void
    {
        $generator = new OpenApiGenerator();
        $document = $this->generate([
            $this->route('first', '/collide', 'McpActionTool', 'MultiVerb', ['GET']),
            $this->route('second', '/collide', 'McpActionTool', 'Greet', ['GET']),
        ], null, $generator);

        $this->assertSame('first', $this->operation($document, '/collide', 'get')['operationId'] ?? null);
        $diagnostics = $generator->getDiagnostics();
        $this->assertCount(1, $diagnostics);
        $this->assertSame(OpenApiGenerator::CODE_DUPLICATE_OPERATION, $diagnostics[0]->code);
        $this->assertSame(Diagnostic::SEVERITY_WARNING, $diagnostics[0]->severity);
        $this->assertStringContainsString('"first"', $diagnostics[0]->message);
        $this->assertSame('second', $diagnostics[0]->symbol);
    }

    public function testDiagnosticsAreResetBetweenRuns(): void
    {
        $generator = new OpenApiGenerator();
        $this->generate([
            $this->route('first', '/collide', 'McpActionTool', 'MultiVerb', ['GET']),
            $this->route('second', '/collide', 'McpActionTool', 'Greet', ['GET']),
        ], null, $generator);
        $this->assertCount(1, $generator->getDiagnostics());

        $this->generate([$this->route('greet', '/greet/{name}', 'McpActionTool', 'Greet')], null, $generator);
        $this->assertSame([], $generator->getDiagnostics());
    }

    public function testPathsAreSortedAndAnEmptyRouteSetYieldsNoPathsAtAll(): void
    {
        $document = $this->generate([
            $this->route('z', '/zebra', 'McpActionTool', 'MultiVerb'),
            $this->route('a', '/aardvark', 'McpActionTool', 'MultiVerb'),
        ]);
        $this->assertSame(['/aardvark', '/zebra'], $this->pathNames($document));

        $empty = $this->generate([]);
        $this->assertSame([], $this->at($empty, 'paths'));
        $this->assertArrayNotHasKey('components', $empty);
    }

    public function testGeneratesFromTheLiveRouteCollectionOfAnAttributeRoutedContext(): void
    {
        // End to end over the real thing: the context's configured Routing
        // service -> RouteCollectionIntrospector -> generator, with no
        // hand-built RouteDefinition anywhere.
        $context = Context::getInstance(self::CONTEXT);
        $routes = (new RouteCollectionIntrospector())->toDefinitions($context->getRouting()->getRouteCollection());
        $document = (new OpenApiGenerator())->generate($routes, $context->getController(), new OpenApiOptions(title: 'Sandbox'));

        $this->assertContains('/mcp-action-tool-test/greet/{name}', $this->pathNames($document));
        $this->assertSame(
            ['type' => 'string', 'minLength' => 2, 'maxLength' => 50],
            $this->at($this->operation($document, '/mcp-action-tool-test/greet/{name}', 'get'), 'parameters', 0, 'schema'),
        );
        $this->assertArrayHasKey(
            'application/json',
            $this->at($this->operation($document, '/mcp-action-tool-test/fluent', 'post'), 'requestBody', 'content'),
        );
    }
}
