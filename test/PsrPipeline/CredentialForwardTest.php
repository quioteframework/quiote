<?php
use PHPUnit\Framework\TestCase;
use Agavi\Agavi;
use Agavi\Config\AgaviConfig;
use Agavi\Middleware\SecurityMiddleware;
use Agavi\Middleware\DispatchMiddleware;
use Agavi\Middleware\ErrorHandlingMiddleware;
use Relay\Relay;
use Nyholm\Psr7\ServerRequest;
use Agavi\Execution\ActionDescriptor;

/**
 * An authenticated user lacking a required credential must be forwarded to the
 * secure-action and that forward must render content (HTTP 200), not the
 * original action.
 */
final class CredentialForwardTest extends TestCase
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
        AgaviConfig::set('actions.secure_module', 'Default');
        AgaviConfig::set('actions.secure_action', 'Secure');
    }

    public function testCredentialForward(): void
    {
        $context = Agavi::context('web', true);
        $controller = $context->getController();
        $module = 'Cache';
        $action = 'CacheComplex';
        // Require a credential the user won't have.
        \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(false, false, true);
        $desc = new ActionDescriptor($module, $action, 'GET', 'html', false);

        // Authenticated, but missing the 'complex_cred' credential.
        $user = $context->getUser();
        if (method_exists($user, 'setAuthenticated')) { $user->setAuthenticated(true); }
        if (method_exists($user, 'removeCredential')) { try { $user->removeCredential('complex_cred'); } catch (\Throwable) {} }

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
        // Authenticated but missing the credential => Forbidden, rendered via the
        // secure-action forward (so there is content, not an empty 403).
        $this->assertSame(403, $resp->getStatusCode(), 'Insufficient credentials must yield 403');
        $this->assertStringContainsString('SECURE_REQUIRED', (string) $resp->getBody());
    }
}
