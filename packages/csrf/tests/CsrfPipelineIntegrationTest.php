<?php

use PHPUnit\Framework\TestCase;
use Quiote\Config\Config;
use Quiote\Context;
use Quiote\Middleware\MiddlewareCatalog;
use Quiote\Middleware\MiddlewarePipeline;
use Quiote\Security\Csrf\CsrfManager;
use Quiote\Security\Csrf\CsrfPlugin;
use Quiote\Plugin\PluginRegistrar;
use Nyholm\Psr7\ServerRequest;

/**
 * End-to-end: CsrfPlugin's contributions actually enforce CSRF when reached
 * through the real, fully-wired pipeline (Quiote::bootstrap() -> CsrfPlugin
 * -> MiddlewareCatalog -> MiddlewarePipeline -> real routing -> real
 * dispatch), not just the middleware classes tested in isolation by
 * CsrfTest.php. tests/bootstrap.php disables CSRF globally for the rest of
 * the suite (core.csrf.enabled=false), so every test in the suite except
 * this one and CsrfTest.php never actually drives a request through an
 * enforcing CSRF middleware -- this test exists specifically to prove the
 * wiring, not just the standalone logic, actually works.
 *
 * Uses the sandbox app's attr_routing.add route (POST /attr-routing/new),
 * an existing attribute-routed action that requires no other setup to
 * dispatch successfully.
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
final class CsrfPipelineIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        Config::set('core.app_dir', $root . '/tests/sandbox/app', true, true);
        Config::set('core.module_dir', $root . '/tests/sandbox/app/Modules', true, true);
        require_once $root . '/vendor/autoload.php';
        require_once $root . '/Quiote/Quiote.php';
        \Quiote\Quiote::bootstrap('testing', 'web', ['prewarm' => false]);

        Config::set('core.csrf.enabled', true);
        MiddlewareCatalog::reset();
        (new CsrfPlugin())->register(new PluginRegistrar('quiote/csrf'));

        // testing.* environment uses NullStorage; swap in an in-memory one so
        // the CSRF token manager can actually persist/retrieve a token within
        // this test.
        $ctx = $this->context();
        $ro = new \ReflectionObject($ctx);
        $prop = $ro->getProperty('storage');
        $prop->setValue($ctx, new class {
            private array $data = [];
            public function store(string $id, mixed $data): bool { $this->data[$id] = $data; return true; }
            public function retrieve($key) { return $this->data[$key] ?? null; }
            public function remove($key) { unset($this->data[$key]); }
        });
    }

    private function context(): Context
    {
        return Context::getInstance('web');
    }

    private function pipeline(): MiddlewarePipeline
    {
        return new MiddlewarePipeline($this->context());
    }

    private function sessionCookieRequest(string $method, string $uri): ServerRequest
    {
        return (new ServerRequest($method, $uri))->withCookieParams([session_name() => 'fake-session-id']);
    }

    public function testUnsafeRequestWithoutTokenIsRejectedByTheRealPipeline(): void
    {
        $response = $this->pipeline()->handle(
            $this->sessionCookieRequest('POST', 'http://localhost/attr-routing/new')
        );

        $this->assertSame(403, $response->getStatusCode(), 'a real dispatch, wired end-to-end through CsrfPlugin, must reject a tokenless unsafe request');
        $this->assertSame('failed', $response->getHeaderLine('X-Quiote-Csrf'));
    }

    public function testUnsafeRequestWithValidTokenReachesTheAction(): void
    {
        $token = (new CsrfManager($this->context()))->getTokenValue();

        $response = $this->pipeline()->handle(
            $this->sessionCookieRequest('POST', 'http://localhost/attr-routing/new')
                ->withParsedBody(['_csrf_token' => $token])
        );

        $this->assertNotSame(403, $response->getStatusCode(), 'a valid token must let the request reach the action, wired end-to-end through CsrfPlugin');
    }
}
