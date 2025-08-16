<?php
namespace Agavi\Runtime;

use Agavi\AgaviContext;
use Agavi\Middleware\MiddlewarePipeline;
use Agavi\Middleware\ErrorHandlingMiddleware;
use Agavi\Middleware\ExecutionTimeMiddleware;
use Agavi\Middleware\RoutingMiddleware;
use Agavi\Middleware\SecurityMiddleware;
use Agavi\Middleware\DispatchMiddleware;
use Agavi\Middleware\AssetAggregationMiddleware;
use Agavi\Http\PsrServerRequestAdapter;
use Agavi\Http\SimpleUri;
use Agavi\Http\SimpleStream;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Agavi\Http\PsrResponseAdapter;

class PsrPipelineBuilder
{
    public function __construct(private AgaviContext $context) {}

    public function buildRequestFromGlobals(): ServerRequestInterface
    {
        $legacyReq = $this->context->getRequest();
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $authority = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $pathQuery = $_SERVER['REQUEST_URI'] ?? '/';
        $uri = new SimpleUri($scheme . '://' . $authority . $pathQuery);
        $body = SimpleStream::fromString(@file_get_contents('php://input') ?: '');
        $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
        /** @var \Agavi\Request\AgaviRequest $legacyReqTyped */
        $legacyReqTyped = $legacyReq; // help static analysis
        return new PsrServerRequestAdapter($legacyReqTyped, $uri, $_SERVER['REQUEST_METHOD'] ?? 'GET', $body, $_SERVER, $headers, $_COOKIE, $_GET, $_POST, []);
    }

    public function buildDispatcher(RequestHandlerInterface $finalHandler): RequestHandlerInterface
    {
        $pipeline = new MiddlewarePipeline($finalHandler);
        // Ordering: routing -> security -> dispatch -> assets -> timing (finalize)
        $pipeline->add('RoutingMiddleware', new RoutingMiddleware($this->context->getRouting(), $this->context->getController()), 'routing');
        $pipeline->add('SecurityMiddleware', new SecurityMiddleware($this->context->getController()), 'before_action');
        $pipeline->add('DispatchMiddleware', new DispatchMiddleware($this->context->getController()), 'action');
        $pipeline->add('AssetAggregationMiddleware', new AssetAggregationMiddleware(), 'post');
        $pipeline->add('ExecutionTimeMiddleware', new ExecutionTimeMiddleware(), 'finalize', -10);
        $handler = $pipeline->build();
        return new class(new ErrorHandlingMiddleware(), $handler) implements RequestHandlerInterface {
            public function __construct(private ErrorHandlingMiddleware $err, private RequestHandlerInterface $next) {}
            public function handle(ServerRequestInterface $request): ResponseInterface { return $this->err->process($request, $this->next); }
        };
    }

    public function defaultFinalHandler(): RequestHandlerInterface
    {
        return new class($this->context) implements RequestHandlerInterface {
            public function __construct(private AgaviContext $ctx) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $response = $this->ctx->getController()->getGlobalResponse();
                return new PsrResponseAdapter($response);
            }
        };
    }
}
