<?php
use PHPUnit\Framework\TestCase;
use Quiote\Quiote;
use Quiote\Config\Config;
use Quiote\Middleware\SecurityMiddleware;
use Quiote\Middleware\DispatchMiddleware;
use Quiote\Middleware\ErrorHandlingMiddleware;
use Quiote\Middleware\TraceMiddleware;
use Relay\Relay;
use Nyholm\Psr7\ServerRequest;
use Quiote\Execution\ActionDescriptor;

/**
 * An unauthenticated user hitting an auth-required action must be forwarded to
 * the login action, and that forward must render content (HTTP 200).
 */
final class SecurityForwardTest extends TestCase
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

    protected function setUp(): void
    {
        Config::set('core.use_security', true);
        Config::set('actions.login_module', 'Default');
        Config::set('actions.login_action', 'Login');
        Config::set('actions.secure_module', 'Default');
        Config::set('actions.secure_action', 'Secure');
        try {
            $user = Quiote::context('web', true)->getUser();
            if (method_exists($user, 'setAuthenticated')) { $user->setAuthenticated(false); }
        } catch (\Throwable) {}
    }

    public function testSecureActionForwardProducesContent(): void
    {
        $context = Quiote::context('web', true);
        $controller = $context->getController();
        $module = 'Cache';
        $action = 'CacheComplex';
        // Require authentication; user is logged out -> login forward.
        \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(false, true, false);
        $desc = new ActionDescriptor($module, $action, 'GET', 'html', false);

        $user = $context->getUser();
        if (method_exists($user, 'setAuthenticated')) { $user->setAuthenticated(false); }

        $stack = [
            new ErrorHandlingMiddleware(),
            new TraceMiddleware(true),
            new SecurityMiddleware($controller),
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
