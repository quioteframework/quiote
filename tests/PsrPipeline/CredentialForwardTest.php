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
 * An authenticated user lacking a required credential must be forwarded to the
 * secure-action and that forward must render content (HTTP 200), not the
 * original action.
 */
final class CredentialForwardTest extends TestCase
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
        Config::set('actions.secure_module', 'Default');
        Config::set('actions.secure_action', 'Secure');
    }

    public function testCredentialForward(): void
    {
        $context = Quiote::context('web', true);
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
