<?php
use PHPUnit\Framework\TestCase;
use Agavi\Agavi;
use Agavi\Config\AgaviConfig;
use Agavi\Http\SimpleUri;
use Agavi\Http\SimpleStream;
use Agavi\Http\PsrServerRequestAdapter;
use Agavi\Middleware\MiddlewareDispatcher;
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
    $this->assertInstanceOf(AgaviRequest::class, $legacyReq, 'Legacy request must be AgaviRequest derived');
        $uri = new SimpleUri('http://localhost/');
        $body = SimpleStream::fromString('');
        $psrReq = new PsrServerRequestAdapter($legacyReq, $uri, 'GET', $body, $_SERVER, [], [], [], [], []);
    $finalHandler = new class($context) implements Psr\Http\Server\RequestHandlerInterface { public function __construct(private $ctx){} public function handle(Psr\Http\Message\ServerRequestInterface $r): Psr\Http\Message\ResponseInterface { $resp = $this->ctx->getController()->getGlobalResponse(); return new PsrResponseAdapter($resp); } };
        $dispatcher = new MiddlewareDispatcher($finalHandler);
        $dispatcher->add(new ExecutionTimeMiddleware());
        $dispatcher->add(new RoutingMiddleware($context->getRouting(), $context->getController()));
        $dispatcher->add(new SecurityMiddleware($context->getController()));
        $dispatcher->add(new DispatchMiddleware($context->getController()));
        $dispatcher->add(new AssetAggregationMiddleware());
        $response = $dispatcher->handle($psrReq);
        $this->assertNotNull($response->getBody());
        $this->assertGreaterThanOrEqual(200, $response->getStatusCode());
    }
}
