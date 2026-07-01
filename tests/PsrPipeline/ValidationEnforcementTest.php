<?php
use PHPUnit\Framework\TestCase;
use Quiote\Quiote;
use Quiote\Config\Config;
use Quiote\Middleware\SecurityMiddleware;
use Quiote\Middleware\DispatchMiddleware;
use Quiote\Middleware\ErrorHandlingMiddleware;
use Relay\Relay;
use Nyholm\Psr7\ServerRequest;
use Quiote\Execution\ActionDescriptor;

/**
 * A non-simple action dispatched WITHOUT ValidationMiddleware in the stack must
 * fail closed (DispatchMiddleware returns 500) rather than run unvalidated.
 */
final class ValidationEnforcementTest extends TestCase
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
    }

    public function testNonSimpleActionWithoutValidationMiddlewareFails(): void
    {
        $context = Quiote::context('web', true);
        $controller = $context->getController();
        $module = 'Cache';
        $action = 'CacheComplex'; // non-simple action
        \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(false, false, false);
        $desc = new ActionDescriptor($module, $action, 'GET', 'html', false);

        // Intentionally omit ValidationMiddleware. DispatchMiddleware is terminal.
        $stack = [
            new ErrorHandlingMiddleware(),
            new SecurityMiddleware($controller),
            new DispatchMiddleware($controller),
        ];

        $req = (new ServerRequest('GET', 'http://localhost/'))
            ->withAttribute('module', $module)
            ->withAttribute('action', $action)
            ->withAttribute('output_type', 'html')
            ->withAttribute(ActionDescriptor::class, $desc);

        $resp = (new Relay($stack))->handle($req);
        $this->assertSame(500, $resp->getStatusCode(), 'Non-simple action without validation must fail with 500');
    }
}
