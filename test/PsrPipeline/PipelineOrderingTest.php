<?php

use PHPUnit\Framework\TestCase;
use Agavi\Agavi;
use Agavi\Config\AgaviConfig;
use Agavi\Middleware\TimingMiddleware;
use Agavi\Middleware\TraceMiddleware;
use Agavi\Middleware\SecurityMiddleware;
use Agavi\Middleware\ValidationMiddleware;
use Agavi\Middleware\DispatchMiddleware;
use Agavi\Middleware\ErrorHandlingMiddleware;
use Relay\Relay;
use Nyholm\Psr7\ServerRequest;
use Agavi\Execution\ExecutionState;
use Agavi\Execution\ActionDescriptor;

/**
 * Verifies a composed stack runs end-to-end and that response-decorating
 * middleware (TraceMiddleware) contribute their headers.
 */
final class PipelineOrderingTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        AgaviConfig::set('core.app_dir', $root . '/test/sandbox/app', true, true);
        AgaviConfig::set('core.module_dir', $root . '/test/sandbox/app/Modules', true, true);
        $vendorAutoload = $root . '/vendor/autoload.php';
        if (is_readable($vendorAutoload)) { require_once $vendorAutoload; }
        require_once $root . '/src/Agavi.php';
        Agavi::bootstrap('testing', 'web', ['prewarm' => false]);
        $root = dirname(__DIR__, 2);
        AgaviConfig::set('core.agavi_dir', $root . '/src', true, true);
    }

    public function testOrderingAndTrace(): void
    {
        $context = Agavi::context('web', true);
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
        $this->assertTrue($resp->hasHeader('X-Agavi-Trace'));
    }
}
