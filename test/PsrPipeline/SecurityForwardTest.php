<?php
use PHPUnit\Framework\TestCase;
use Agavi\Agavi;
use Agavi\Config\AgaviConfig;
use Agavi\Middleware\SecurityMiddleware;
use Agavi\Middleware\DispatchMiddleware;
use Agavi\Middleware\ErrorHandlingMiddleware;
use Agavi\Middleware\TraceMiddleware;
use Relay\Relay;
use Nyholm\Psr7\ServerRequest;
use Agavi\Execution\ActionDescriptor;

/**
 * An unauthenticated user hitting an auth-required action must be forwarded to
 * the login action, and that forward must render content (HTTP 200).
 */
final class SecurityForwardTest extends TestCase
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

    protected function setUp(): void
    {
        AgaviConfig::set('core.use_security', true);
        AgaviConfig::set('actions.login_module', 'Default');
        AgaviConfig::set('actions.login_action', 'Login');
        AgaviConfig::set('actions.secure_module', 'Default');
        AgaviConfig::set('actions.secure_action', 'Secure');
        try {
            $user = Agavi::context('web', true)->getUser();
            if ($user && method_exists($user, 'setAuthenticated')) { $user->setAuthenticated(false); }
        } catch (\Throwable) {}
    }

    public function testSecureActionForwardProducesContent(): void
    {
        $context = Agavi::context('web', true);
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
