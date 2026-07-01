<?php

use PHPUnit\Framework\TestCase;
use Quiote\Quiote;
use Quiote\Config\Config;
use Quiote\Middleware\TimingMiddleware;
use Quiote\Middleware\TraceMiddleware;
use Quiote\Middleware\SecurityMiddleware;
use Quiote\Middleware\ValidationMiddleware;
use Quiote\Middleware\DispatchMiddleware;
use Quiote\Middleware\ErrorHandlingMiddleware;
use Relay\Relay;
use Nyholm\Psr7\ServerRequest;
use Quiote\Execution\ExecutionState;
use Quiote\Execution\ActionDescriptor;

/**
 * Verifies a composed stack runs end-to-end and that response-decorating
 * middleware (TraceMiddleware) contribute their headers.
 */
final class PipelineOrderingTest extends TestCase
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
        $root = dirname(__DIR__, 2);
        Config::set('core.quiote_dir', $root . '/Quiote', true, true);
    }

    public function testOrderingAndTrace(): void
    {
        $context = Quiote::context('web', true);
        $controller = $context->getController();
        $module = 'Cache';
        $action = 'CacheComplex';
        \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(false, false, false);
        $desc = new ActionDescriptor($module, $action, 'GET', 'html', false);

        $stack = [
            new ErrorHandlingMiddleware(),
            new TimingMiddleware(false),
            new TraceMiddleware(true),
            new SecurityMiddleware($controller),
            new ValidationMiddleware($controller),
            new DispatchMiddleware($controller),
        ];

        $req = (new ServerRequest('GET', 'http://localhost/'))
            ->withAttribute('module', $module)
            ->withAttribute('action', $action)
            ->withAttribute('output_type', 'html')
            ->withAttribute(ActionDescriptor::class, $desc)
            ->withAttribute(ExecutionState::class, new ExecutionState());

        $initialLevel = ob_get_level();
        $resp = (new Relay($stack))->handle($req);
        while (ob_get_level() > $initialLevel) {
            @ob_end_clean();
        }

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertTrue($resp->hasHeader('X-Quiote-Trace'));
    }
}
