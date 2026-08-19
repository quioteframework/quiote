<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quiote\Context;
use Quiote\Middleware\Config\MiddlewareConfigRegistry;
use Quiote\Middleware\MiddlewareCatalog;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteCodec;
use Quiote\Replay\Cassette\CassetteId;
use Quiote\Replay\Replay\ReplayException;
use Quiote\Replay\Testing\ReplayTestCase;
use Quiote\Replay\Store\FileCassetteStore;

/**
 * `ReplayTestCase::replay()` -- proves it reconstructs a cassette's request
 * and dispatches it through the real pipeline (the same mechanics
 * `HttpTestCaseTest` covers for `get()`/`post()`), independently of
 * {@see \Quiote\Replay\Replay\ReplayEngine} (deliberately not used here --
 * see the class's own docblock for why).
 */
final class ReplayTestCaseTest extends ReplayTestCase
{
    /** @var list<string> */
    private array $filesToDelete = [];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        MiddlewareCatalog::reset();
        MiddlewareConfigRegistry::reset();
    }

    #[\Override]
    protected function tearDown(): void
    {
        MiddlewareCatalog::reset();
        MiddlewareConfigRegistry::reset();
        foreach ($this->filesToDelete as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        parent::tearDown();
    }

    private static function echoMiddleware(): callable
    {
        return static fn(): MiddlewareInterface => new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return new Response(200, ['Content-Type' => 'application/json'], (string)json_encode([
                    'method' => $request->getMethod(),
                    'uri' => (string)$request->getUri(),
                    'testHeader' => $request->getHeaderLine('X-Test-Header'),
                ]));
            }
        };
    }

    private function replaceStackWithEcho(): void
    {
        MiddlewareCatalog::replaceCoreStack(
            fn(Context $c) => [(self::echoMiddleware())()],
            MiddlewareCatalog::REPLACE_CORE_STACK_ACKNOWLEDGEMENT
        );
    }

    /** @param array<string, mixed> $request */
    private function cassetteFile(array $request): string
    {
        $cassette = new Cassette(
            schemaVersion: CassetteCodec::CURRENT_SCHEMA_VERSION,
            meta: ['id' => 'AAA'],
            request: $request,
            resolved: [],
            session: null,
            user: null,
            effects: [],
            response: ['status' => 200, 'headers' => [], 'body' => ['encoding' => 'utf8', 'content' => '', 'truncated' => false]],
            exception: null,
            log: null,
        );

        $dir = sys_get_temp_dir() . '/quiote-replay-testcase-' . bin2hex(random_bytes(6));
        $store = new FileCassetteStore($dir);
        $id = CassetteId::fromRaw('AAA');
        $store->put($id, $cassette);
        $path = $dir . '/' . $id->slug . '.qcast';
        $this->filesToDelete[] = $path;

        return $path;
    }

    public function testReplayDispatchesTheReconstructedRequestThroughTheRealPipeline(): void
    {
        $this->replaceStackWithEcho();
        $path = $this->cassetteFile([
            'method' => 'GET',
            'uri' => '/attr-routing?x=1',
            'headers' => ['X-Test-Header' => ['present']],
            'cookies' => [],
            'body' => ['encoding' => 'utf8', 'content' => '', 'truncated' => false],
            'server' => [],
        ]);

        $response = $this->replay($path);

        $response->assertOk();
        $body = $response->json();
        $this->assertSame('GET', $body['method']);
        $this->assertSame('/attr-routing?x=1', $body['uri']);
        $this->assertSame('present', $body['testHeader']);
    }

    public function testReplayReturnsAUsableTestResponseForChainedAssertions(): void
    {
        $this->replaceStackWithEcho();
        $path = $this->cassetteFile([
            'method' => 'POST',
            'uri' => '/orders/42',
            'headers' => [],
            'cookies' => [],
            'body' => ['encoding' => 'utf8', 'content' => '', 'truncated' => false],
            'server' => [],
        ]);

        $this->replay($path)
            ->assertOk()
            ->assertJson(['method' => 'POST']);
    }

    public function testReplayThrowsAClearErrorForANoRecordSkeletonCassette(): void
    {
        $path = $this->cassetteFile(['method' => null, 'uri' => null]);

        $this->expectException(ReplayException::class);
        $this->expectExceptionMessageMatches('/no replayable request/');
        $this->replay($path);
    }

    public function testReplayThrowsAClearErrorWhenTheCassetteFileIsMissing(): void
    {
        $this->expectException(ReplayException::class);
        $this->expectExceptionMessageMatches('/Could not read cassette file/');
        $this->replay(sys_get_temp_dir() . '/quiote-replay-testcase-does-not-exist.qcast');
    }
}
