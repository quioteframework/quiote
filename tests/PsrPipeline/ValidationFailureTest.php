<?php
use PHPUnit\Framework\TestCase;
use Quiote\Quiote;
use Quiote\Config\Config;
use Quiote\Middleware\SecurityMiddleware;
use Quiote\Middleware\ValidationMiddleware;
use Quiote\Middleware\DispatchMiddleware;
use Quiote\Middleware\ErrorHandlingMiddleware;
use Quiote\Middleware\OutputTypeSyncMiddleware;
use Quiote\Middleware\ContentNegotiationMiddleware;
use Quiote\Middleware\RoutingMiddleware;
use Relay\Relay;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Nyholm\Psr7\ServerRequest;
use Quiote\Execution\ActionDescriptor;

/**
 * Run in a separate process: setUpBeforeClass() bootstraps with a real
 * environment name, which Quiote::bootstrap() locks core.environment
 * read-only to for the rest of the process (see DispatchMiddlewareDeeperCoverageTest's
 * docblock for the full story). #[RunTestsInSeparateProcesses] contains that
 * lock to this class's own process.
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
final class ValidationFailureTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__,2);
    Config::set('core.app_dir', $root . '/tests/sandbox/app', true, true);
        $vendorAutoload = $root . '/vendor/autoload.php';
        if (is_readable($vendorAutoload)) { require_once $vendorAutoload; }
        require_once $root . '/Quiote/Quiote.php';
        Quiote::bootstrap('testing','web', ['prewarm'=>false]);
    }

    public function testValidationFailureInvokesHandleError(): void
    {
        $context = Quiote::context('web', true);
        $controller = $context->getController();
    $module = 'Cache';
    $action = 'CacheComplex'; // has handleError implementation
    \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(true,false,false);
        $desc = new ActionDescriptor($module, $action, 'GET', 'html', false);
        // Minimal stack replicating ordering: ErrorHandling -> ContentNegotiation -> Routing -> OutputTypeSync -> Security -> Validation -> Dispatch
        $stack = [
            new ErrorHandlingMiddleware(),
            new SecurityMiddleware($controller),
            new ValidationMiddleware($controller), // inject controller so action instantiation works reliably
            new DispatchMiddleware($controller),
        ];
        $handler = new readonly class(new Relay($stack)) implements RequestHandlerInterface { public function __construct(private Relay $relay){} public function handle(ServerRequestInterface $r): ResponseInterface { return $this->relay->handle($r); } };
    $req = (new ServerRequest('GET', 'http://localhost/?fail=1'))
            ->withAttribute('module', $module)
            ->withAttribute('action', $action)
            ->withAttribute('output_type', 'html')
            ->withAttribute(ActionDescriptor::class, $desc);
        $resp = $handler->handle($req);
    // Validation failure should return 400 Bad Request (handleError view executed by ValidationMiddleware)
    $this->assertSame(400, $resp->getStatusCode(), 'Expected validation failure to short-circuit with 400');
        // CacheComplex::handleError renders the "<div>COMPLEX_ERROR</div>" view.
        $this->assertStringContainsStringIgnoringCase('error', (string)$resp->getBody());
    }

    public function testValidationFailureReturnsJsonWhenJsonNegotiated(): void
    {
        $context = Quiote::context('web', true);
        $controller = $context->getController();
        $module = 'Cache';
        $action = 'CacheComplex';
        \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(true, false, false);
        // Negotiated output type is json (what an Accept: application/json request yields).
        $desc = new ActionDescriptor($module, $action, 'GET', 'json', false);
        $stack = [
            new ErrorHandlingMiddleware(),
            new SecurityMiddleware($controller),
            new ValidationMiddleware($controller),
            new DispatchMiddleware($controller),
        ];
        $handler = new readonly class(new Relay($stack)) implements RequestHandlerInterface {
            public function __construct(private Relay $relay) {}
            public function handle(ServerRequestInterface $r): ResponseInterface { return $this->relay->handle($r); }
        };
        $req = (new ServerRequest('GET', 'http://localhost/?fail=1'))
            ->withAttribute('module', $module)
            ->withAttribute('action', $action)
            ->withAttribute('output_type', 'json')
            ->withAttribute(ActionDescriptor::class, $desc);
        $resp = $handler->handle($req);

        $this->assertSame(400, $resp->getStatusCode());
        // RFC 9457 Problem Details, not the HTML error view / blank body.
        $this->assertStringContainsString('application/problem+json', $resp->getHeaderLine('Content-Type'));
        $body = (string) $resp->getBody();
        $this->assertNotSame('', $body, 'JSON validation failure must not return an empty body');
        $decoded = json_decode($body, true);
        $this->assertIsArray($decoded);
        // Core Problem Details members.
        $this->assertArrayHasKey('type', $decoded);
        $this->assertArrayHasKey('title', $decoded);
        $this->assertSame(400, $decoded['status'] ?? null);
        // Validation extension member carrying the failures.
        $this->assertArrayHasKey('errors', $decoded, 'Problem Details must carry the validation errors');
    }
}
