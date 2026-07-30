<?php

use Quiote\Testing\UnitTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Execution\ActionDescriptor;
use Quiote\Execution\ExecutionState;
use Quiote\Middleware\ValidationMiddleware;
use Quiote\Middleware\DispatchMiddleware;
use Sandbox\Modules\Snapshot\Actions\HeaderSnapshotAction;

/**
 * End-to-end guard: headers are just as attacker-controlled as query/body
 * parameters (Content-Type, Authorization, X-Forwarded-*, custom headers,
 * etc.). An action with no validators at all must see every header purged by
 * the time its execute*() method runs, through the full
 * ValidationMiddleware -> DispatchMiddleware -> ActionExecutor chain.
 */
class HeaderPurgeEndToEndTest extends UnitTestCase
{
    private ?string $previousCacheDir = null;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('QUIOTE_DISPATCH_CONTEXT=1');
        putenv('QUIOTE_DISPATCH_CONTEXT_SIMPLE=1');
        $tmpCache = sys_get_temp_dir() . '/quiote_test_cache';
        if (!is_dir($tmpCache)) { @mkdir($tmpCache, 0777, true); }
        $this->previousCacheDir = \Quiote\Config\Config::getNullableString('core.cache_dir');
        \Quiote\Config\Config::set('core.cache_dir', $tmpCache);
        $this->getContext()->getController()->initializeModule('Snapshot');
        HeaderSnapshotAction::$seenHeaders = [];
    }

    public function testUnvalidatedHeadersArePurgedBeforeExecuteRuns(): void
    {
        $controller = $this->getContext()->getController();
        $descriptor = ActionDescriptor::fromController($controller, 'Snapshot', 'HeaderSnapshotAction', 'GET', 'html');

        $request = (new ServerRequest('GET', 'http://localhost/snapshot/header'))
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Authorization', 'Bearer secret-token')
            ->withHeader('X-My-Special-Header', 'attacker-controlled-value')
            ->withAttribute('module', 'Snapshot')
            ->withAttribute('action', 'HeaderSnapshotAction')
            ->withAttribute('output_type', 'html')
            ->withAttribute(ActionDescriptor::class, $descriptor)
            ->withAttribute(ExecutionState::class, new ExecutionState());

        $dispatch = new DispatchMiddleware($controller);
        $final = new class(new Psr17Factory) implements RequestHandlerInterface {
            public function __construct(private Psr17Factory $f) {}
            public function handle(ServerRequestInterface $r): ResponseInterface { return $this->f->createResponse(200); }
        };
        $dispatchAsHandler = new class($dispatch, $final) implements RequestHandlerInterface {
            public function __construct(private DispatchMiddleware $mw, private RequestHandlerInterface $final) {}
            public function handle(ServerRequestInterface $r): ResponseInterface { return $this->mw->process($r, $this->final); }
        };

        $validation = new ValidationMiddleware($controller);
        $resp = $validation->process($request, $dispatchAsHandler);

        $seen = HeaderSnapshotAction::$seenHeaders;

        $this->assertSame('HEADER_OK', (string) $resp->getBody());
        $this->assertSame('', $seen['content-type'] ?? 'UNSET', 'Content-Type must be purged before execute*() runs');
        $this->assertSame('', $seen['authorization'] ?? 'UNSET', 'Authorization must be purged before execute*() runs');
        $this->assertSame('', $seen['x-my-special-header'] ?? 'UNSET', 'Arbitrary custom header must be purged before execute*() runs');
    }

    protected function tearDown(): void
    {
        // Put core.cache_dir back: it is a process-global directive, so leaving it
        // pointed at this test's temp directory makes every later test compile its
        // config cache in there -- or, once the directory is cleaned up, wherever
        // tempnam() falls back to.
        if ($this->previousCacheDir !== null) {
            \Quiote\Config\Config::set('core.cache_dir', $this->previousCacheDir, true);
        }
        parent::tearDown();
    }
}
