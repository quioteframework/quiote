<?php
use PHPUnit\Framework\TestCase;
use Agavi\Agavi;
use Agavi\Config\AgaviConfig;
use Agavi\Middleware\ErrorHandlingMiddleware;
use Agavi\Middleware\SecurityMiddleware;
use Agavi\Middleware\ValidationMiddleware;
use Agavi\Middleware\DispatchMiddleware;
use Relay\Relay;
use Nyholm\Psr7\ServerRequest;
use Agavi\Execution\ActionDescriptor;

/**
 * Smoke test: a composed middleware stack dispatches an action and produces a
 * successful PSR-7 response.
 */
final class BasicPipelineTest extends TestCase
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
    }

    public function testPipelineProducesResponse(): void
    {
        $context = Agavi::context('web', true);
        $controller = $context->getController();
        $module = 'Cache';
        $action = 'CacheComplex';
        \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(false, false, false);
        $desc = new ActionDescriptor($module, $action, 'GET', 'html', false);

        // Full happy-path stack (validation present so the non-simple action runs).
        $stack = [
            new ErrorHandlingMiddleware(),
            new SecurityMiddleware($controller),
            new ValidationMiddleware($controller),
            new DispatchMiddleware($controller),
        ];

        $req = (new ServerRequest('GET', 'http://localhost/'))
            ->withAttribute('module', $module)
            ->withAttribute('action', $action)
            ->withAttribute('output_type', 'html')
            ->withAttribute(ActionDescriptor::class, $desc);

        $resp = (new Relay($stack))->handle($req);
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertNotEmpty((string) $resp->getBody());
    }
}
