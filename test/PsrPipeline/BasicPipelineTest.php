<?php
use PHPUnit\Framework\TestCase;
use Agavi\Agavi;
use Agavi\Config\AgaviConfig;
use Agavi\Http\SimpleUri;
use Agavi\Http\SimpleStream;
use Nyholm\Psr7\Factory\Psr17Factory;
// PsrServerRequestAdapter removed; AgaviWebRequest implements ServerRequestInterface directly
use Agavi\Middleware\MiddlewarePipeline;
use Agavi\Middleware\ErrorHandlingMiddleware;
use Agavi\Middleware\RoutingMiddleware;
use Agavi\Middleware\SecurityMiddleware;
use Agavi\Middleware\DispatchMiddleware;
use Agavi\Middleware\ExecutionTimeMiddleware;
use Agavi\Middleware\AssetAggregationMiddleware;
use Agavi\Http\PsrResponseAdapter;
use Agavi\Request\AgaviRequest;

final class BasicPipelineTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__,2);
        AgaviConfig::set('core.app_dir', $root . '/app', true, true);
        $vendorAutoload = $root . '/vendor/autoload.php';
        if (is_readable($vendorAutoload)) { require_once $vendorAutoload; }
        require_once $root . '/src/Agavi.php';
        Agavi::bootstrap('test','web', ['prewarm'=>false]);
    }

    public function testPipelineProducesResponse(): void
    {
        $context = Agavi::context('web', true);
    $legacyReq = $context->getRequest();
    // During PSR-7 migration the legacy request may no longer extend AgaviRequest directly,
    // but must implement the minimal API used by PsrServerRequestAdapter. Assert interface shape instead.
    $this->assertIsObject($legacyReq, 'Context must return a request object');
    // Ensure PSR-7 methods exist (AgaviWebRequest implements ServerRequestInterface)
    $this->assertTrue(method_exists($legacyReq, 'withAttribute'), 'Request must support withAttribute (PSR-7)');
        // Build a standalone PSR-7 request and attach to legacy web request (bridge)
        $uri = new SimpleUri('http://localhost/');
        $body = SimpleStream::fromString('');
        $psr17 = new Psr17Factory();
        $serverReq = $psr17->createServerRequest('GET', (string)$uri, $_SERVER);
        $serverReq = $serverReq->withBody($body);
        if (method_exists($legacyReq, 'attachPsrRequest')) {
            $legacyReq->attachPsrRequest($serverReq);
        }
        $psrReq = $legacyReq->withAttribute('module', AgaviConfig::get('actions.default_module'))
                            ->withAttribute('action', AgaviConfig::get('actions.default_action'));
    $finalHandler = new class($context) implements Psr\Http\Server\RequestHandlerInterface { public function __construct(private $ctx){} public function handle(Psr\Http\Message\ServerRequestInterface $r): Psr\Http\Message\ResponseInterface { $resp = $this->ctx->getController()->getGlobalResponse(); return new PsrResponseAdapter($resp); } };
    $pipeline = new MiddlewarePipeline($finalHandler);
    $pipeline->add('RoutingMiddleware', new RoutingMiddleware($context->getRouting(), $context->getController()), 'routing');
    $pipeline->add('SecurityMiddleware', new SecurityMiddleware($context->getController()), 'before_action');
    $pipeline->add('DispatchMiddleware', new DispatchMiddleware($context->getController()), 'action');
    $pipeline->add('AssetAggregationMiddleware', new AssetAggregationMiddleware(), 'post');
    $pipeline->add('ExecutionTimeMiddleware', new ExecutionTimeMiddleware(), 'finalize');
    $handler = $pipeline->build();
    $handler = new readonly class(new ErrorHandlingMiddleware(), $handler) implements Psr\Http\Server\RequestHandlerInterface { public function __construct(private ErrorHandlingMiddleware $err, private Psr\Http\Server\RequestHandlerInterface $next) {} public function handle(Psr\Http\Message\ServerRequestInterface $r): Psr\Http\Message\ResponseInterface { return $this->err->process($r, $this->next); } };
    $response = $handler->handle($psrReq);
        $this->assertNotNull($response->getBody());
        $this->assertGreaterThanOrEqual(200, $response->getStatusCode());
    }
}
