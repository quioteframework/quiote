<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use PHPUnit\Framework\TestCase;
use Quiote\Context;
use Quiote\Middleware\Config\MiddlewareConfigRegistry;
use Quiote\Middleware\MiddlewareCatalog;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteCodec;
use Quiote\Replay\Cassette\CassetteId;
use Quiote\Replay\Store\FileCassetteStore;
use Quiote\Replay\Testing\TestEmitter;

/**
 * The meta-test `docs/RECORD_REPLAY_PLAN.md` §14 calls for: emits a real
 * test from a real cassette, `require`s the generated file, and actually
 * runs the emitted case -- proving the generator's output is not merely
 * syntactically valid PHP (already covered by {@see TestEmitterTest}) but a
 * test that genuinely passes when the recorded response still matches.
 */
final class TestEmitterMetaTest extends TestCase
{
    /** @var list<string> */
    private array $toDelete = [];

    protected function tearDown(): void
    {
        MiddlewareCatalog::reset();
        MiddlewareConfigRegistry::reset();
        foreach (array_reverse($this->toDelete) as $path) {
            if (is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                @rmdir($path);
            }
        }
        parent::tearDown();
    }

    private static function echoMiddleware(): MiddlewareInterface
    {
        return new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return new Response(200, ['Content-Type' => 'application/json'], (string)json_encode([
                    'method' => $request->getMethod(),
                    'uri' => (string)$request->getUri(),
                ]));
            }
        };
    }

    /**
     * Invokes the generated test's own `setUp()`/test method/`tearDown()` directly via
     * reflection, rather than the public `TestCase::run()` -- `run()` reports its outcome through
     * PHPUnit's own global event system, which would register a deliberately-incomplete inner
     * test (see the second test below) as a real "I" on the whole suite's own result, violating
     * this project's "no F/E/W/R/D/N/I" bar. A plain method call lets an assertion
     * failure/`IncompleteTestError`/other `Throwable` propagate as an ordinary PHP exception to
     * the caller instead, with no such side effect.
     */
    private function runGeneratedTestMethod(object $test, string $methodName): void
    {
        $reflection = new ReflectionObject($test);
        $setUp = $reflection->getMethod('setUp');
        $tearDown = $reflection->getMethod('tearDown');

        $setUp->invoke($test);
        try {
            $reflection->getMethod($methodName)->invoke($test);
        } finally {
            $tearDown->invoke($test);
        }
    }

    public function testAGeneratedPinBehaviourTestActuallyPassesWhenRun(): void
    {
        $dir = sys_get_temp_dir() . '/quiote-test-emitter-meta-' . bin2hex(random_bytes(6));
        mkdir($dir . '/cassettes', 0777, true);
        $this->toDelete[] = $dir . '/cassettes';
        $this->toDelete[] = $dir;

        $rawId = 'META' . bin2hex(random_bytes(4));
        $cassette = new Cassette(
            schemaVersion: CassetteCodec::CURRENT_SCHEMA_VERSION,
            meta: ['id' => $rawId],
            request: [
                'method' => 'GET',
                'uri' => '/attr-routing',
                'headers' => [],
                'cookies' => [],
                'body' => ['encoding' => 'utf8', 'content' => '', 'truncated' => false],
                'server' => [],
            ],
            resolved: [],
            session: null,
            user: null,
            effects: [],
            response: [
                'status' => 200,
                'headers' => ['Content-Type' => ['application/json']],
                'body' => ['encoding' => 'utf8', 'content' => json_encode(['method' => 'GET', 'uri' => '/attr-routing']), 'truncated' => false],
            ],
            exception: null,
            log: null,
        );

        $id = CassetteId::fromRaw($rawId);
        (new FileCassetteStore($dir . '/cassettes'))->put($id, $cassette);

        $artifact = (new TestEmitter())->emit($cassette, $id);
        $testFile = $dir . '/' . basename($artifact->targetHint);
        file_put_contents($testFile, $artifact->phpSource);
        $this->toDelete[] = $testFile;
        $this->toDelete[] = $dir . '/cassettes/' . $id->slug . '.qcast';

        MiddlewareCatalog::replaceCoreStack(
            fn(Context $c) => [self::echoMiddleware()],
            MiddlewareCatalog::REPLACE_CORE_STACK_ACKNOWLEDGEMENT,
        );

        require $testFile;
        $className = 'Replay' . $id->slug . 'Test';
        $test = new $className('testReproducesRecordedResponse');

        // No exception == the generated assertions passed against the real dispatch.
        $this->runGeneratedTestMethod($test, 'testReproducesRecordedResponse');
        $this->addToAssertionCount(1);
    }

    public function testAGeneratedExpectFixedTestReportsIncompleteRatherThanPassingOrFailing(): void
    {
        $dir = sys_get_temp_dir() . '/quiote-test-emitter-meta-' . bin2hex(random_bytes(6));
        mkdir($dir . '/cassettes', 0777, true);
        $this->toDelete[] = $dir . '/cassettes';
        $this->toDelete[] = $dir;

        $rawId = 'META' . bin2hex(random_bytes(4));
        $cassette = new Cassette(
            schemaVersion: CassetteCodec::CURRENT_SCHEMA_VERSION,
            meta: ['id' => $rawId],
            request: [
                'method' => 'GET',
                'uri' => '/attr-routing',
                'headers' => [],
                'cookies' => [],
                'body' => ['encoding' => 'utf8', 'content' => '', 'truncated' => false],
                'server' => [],
            ],
            resolved: [],
            session: null,
            user: null,
            effects: [],
            response: ['status' => 500, 'headers' => [], 'body' => ['encoding' => 'utf8', 'content' => '', 'truncated' => false]],
            exception: ['class' => 'ErrorException', 'message' => 'boom'],
            log: null,
        );

        $id = CassetteId::fromRaw($rawId);
        (new FileCassetteStore($dir . '/cassettes'))->put($id, $cassette);

        $artifact = (new TestEmitter())->emit($cassette, $id, expectFixed: true);
        $testFile = $dir . '/' . basename($artifact->targetHint);
        file_put_contents($testFile, $artifact->phpSource);
        $this->toDelete[] = $testFile;
        $this->toDelete[] = $dir . '/cassettes/' . $id->slug . '.qcast';

        MiddlewareCatalog::replaceCoreStack(
            fn(Context $c) => [self::echoMiddleware()],
            MiddlewareCatalog::REPLACE_CORE_STACK_ACKNOWLEDGEMENT,
        );

        require $testFile;
        $className = 'Replay' . $id->slug . 'Test';
        $test = new $className('testFixesRecordedBug');

        try {
            $this->runGeneratedTestMethod($test, 'testFixesRecordedBug');
            $this->fail('Expected the --expect-fixed skeleton to call markTestIncomplete().');
        } catch (\PHPUnit\Framework\IncompleteTestError $e) {
            $this->assertStringContainsString('Fix the recorded bug', $e->getMessage());
        }
    }
}
