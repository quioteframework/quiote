<?php

use PHPUnit\Framework\TestCase;
use Quiote\Quiote;
use Quiote\Config\Config;
use Quiote\Middleware\MiddlewareCatalog;
use Quiote\Middleware\MiddlewarePipeline;
use Nyholm\Psr7\ServerRequest;
use Quiote\Execution\ExecutionState;
use Quiote\Execution\ActionDescriptor;

/**
 * Verifies middleware.timing.emit_header / middleware.trace.emit_header /
 * middleware.trace.header_name drive TimingMiddleware/TraceMiddleware's
 * constructor arguments through MiddlewarePipeline::doBuild(), instead of
 * requiring a MiddlewareCatalog::register() override.
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
final class TimingTraceHeaderConfigTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        Config::set('core.app_dir', $root . '/tests/sandbox/app', true, true);
        Config::set('core.module_dir', $root . '/tests/sandbox/app/Modules', true, true);
        $vendorAutoload = $root . '/vendor/autoload.php';
        if (is_readable($vendorAutoload)) { require_once $vendorAutoload; }
        require_once $root . '/Quiote/Quiote.php';
        Quiote::bootstrap('testing', 'web', ['prewarm' => false]);
        Config::set('core.quiote_dir', $root . '/Quiote', true, true);
    }

    public function setUp(): void
    {
        MiddlewareCatalog::initialize([]); // default: all enabled
    }

    private function dispatchToCacheAction(): \Psr\Http\Message\ResponseInterface
    {
        $context = Quiote::context('web', true);
        $module = 'Cache';
        $action = 'CacheComplex';
        \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(false, false, false);
        $desc = new ActionDescriptor($module, $action, 'GET', 'html', false);

        $req = (new ServerRequest('GET', 'http://localhost/'))
            ->withAttribute('module', $module)
            ->withAttribute('action', $action)
            ->withAttribute('output_type', 'html')
            ->withAttribute(ActionDescriptor::class, $desc)
            ->withAttribute(ExecutionState::class, new ExecutionState());

        $pipeline = new MiddlewarePipeline($context);
        $initialLevel = ob_get_level();
        try {
            return $pipeline->handle($req);
        } finally {
            while (ob_get_level() > $initialLevel) {
                @ob_end_clean();
            }
        }
    }

    public function testTimingHeaderOffByDefault(): void
    {
        $resp = $this->dispatchToCacheAction();
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertFalse($resp->hasHeader('X-Quiote-Timing'));
    }

    public function testTimingHeaderEnabledViaConfig(): void
    {
        Config::set('middleware.timing.emit_header', true);
        $resp = $this->dispatchToCacheAction();
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertTrue($resp->hasHeader('X-Quiote-Timing'));
        $payload = json_decode($resp->getHeaderLine('X-Quiote-Timing'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('total_ms', $payload);
    }

    public function testTraceHeaderOffByDefault(): void
    {
        $resp = $this->dispatchToCacheAction();
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertFalse($resp->hasHeader('X-Quiote-Trace'));
    }

    public function testTraceHeaderEnabledViaConfig(): void
    {
        Config::set('middleware.trace.emit_header', true);
        $resp = $this->dispatchToCacheAction();
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertTrue($resp->hasHeader('X-Quiote-Trace'));
    }

    public function testTraceHeaderNameOverridableViaConfig(): void
    {
        Config::set('middleware.trace.emit_header', true);
        Config::set('middleware.trace.header_name', 'X-Custom-Trace');
        $resp = $this->dispatchToCacheAction();
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertFalse($resp->hasHeader('X-Quiote-Trace'));
        $this->assertTrue($resp->hasHeader('X-Custom-Trace'));
    }
}
