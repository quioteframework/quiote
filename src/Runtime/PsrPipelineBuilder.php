<?php
namespace Agavi\Runtime;

use Agavi\AgaviContext;
use Agavi\Logging\AgaviDebugLogger;
use Agavi\Middleware\MiddlewarePipeline;
use Agavi\Middleware\ErrorHandlingMiddleware;
use Agavi\Middleware\ExecutionTimeMiddleware;
use Agavi\Middleware\RoutingMiddleware;
use Agavi\Middleware\SecurityMiddleware;
use Agavi\Middleware\SlotMiddleware;
use Agavi\Middleware\DispatchMiddleware;
use Agavi\Middleware\AssetAggregationMiddleware;
use Agavi\Request\AgaviWebRequest;
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
            // Derive parsed body: prefer JSON decode when content type indicates JSON; fallback to $_POST superglobal otherwise.
            $parsedBody = $_POST;
            try {
                $ctHeader = '';
                foreach($headers as $hk=>$hv) { if(strtolower($hk)==='content-type') { $ctHeader = is_array($hv)?implode(',',$hv):$hv; break; } }
                if($ctHeader && stripos($ctHeader,'json') !== false) {
                    $raw = (string)$body; // SimpleStream implements __toString() rewinding stream
                    if($raw !== '') {
                        if(str_starts_with($raw, "\xEF\xBB\xBF")) { $raw = substr($raw,3); }
                        $decoded = json_decode($raw, true);
                        if(json_last_error() === JSON_ERROR_NONE && is_array($decoded)) { $parsedBody = $decoded; }
                    }
                }
            } catch(\Throwable) { /* ignore parse issues at this stage; middleware may handle strict errors */ }
        /** @var \Agavi\Request\AgaviRequest $legacyReqTyped */
        $legacyReqTyped = $legacyReq; // help static analysis
        // Construct AgaviWebRequest directly (it extends Nyholm\Psr7\ServerRequest)
        $agavi = new AgaviWebRequest(
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            $uri,
            $headers,
            $body,
            '1.1',
            $_SERVER
        );
        // Populate with PSR-7 params (withX methods return new immutable instances)
        $agavi = $agavi
            ->withQueryParams($_GET)
            ->withCookieParams($_COOKIE)
            ->withParsedBody($parsedBody);
        return $agavi;
    }

    public function buildDispatcher(RequestHandlerInterface $finalHandler): RequestHandlerInterface
    {
        $pipeline = new MiddlewarePipeline($finalHandler);
        // Ordering: routing -> slot -> security -> dispatch -> assets -> timing (finalize)
        $pipeline->add('RoutingMiddleware', new RoutingMiddleware($this->context->getRouting(), $this->context->getController()), 'routing');
        $pipeline->add('SlotMiddleware', new SlotMiddleware($this->context), 'routing');
        $pipeline->add('SecurityMiddleware', new SecurityMiddleware($this->context->getController()), 'before_action');
        $pipeline->add('DispatchMiddleware', new DispatchMiddleware($this->context->getController()), 'action');
        $pipeline->add('AssetAggregationMiddleware', new AssetAggregationMiddleware(), 'post');
        $pipeline->add('ExecutionTimeMiddleware', new ExecutionTimeMiddleware(), 'finalize', -10);
        $handler = $pipeline->build();
        // Provide logger to ErrorHandlingMiddleware so underlying exception becomes visible in logs
        $context = $this->context;
        $loggerFn = function(\Throwable $e, ServerRequestInterface $r) use ($context) {
            $first = $e->getFile().':'.$e->getLine();
            $trace = $e->getTraceAsString();
            $snippet = substr(str_replace("\n", ' | ', $trace), 0, 1200);
            // Attempt last SQL + query count
            $lastSql = null; $queryCount = null;
            try {
                if (class_exists(\Propel\Propel::class)) {
                    $pdo = \Propel\Propel::getConnection('mdi');
                    if ($pdo && method_exists($pdo, 'getLastExecutedQuery')) { $lastSql = $pdo->getLastExecutedQuery(); }
                    if ($pdo && method_exists($pdo, 'getQueryCount')) { try { $queryCount = $pdo->getQueryCount(); } catch (\Throwable) {} }
                }
            } catch (\Throwable) {}
            // Route / module / action extraction
            $route = $r->getAttribute('_route') ?? null;
            $module = $r->getAttribute('_module') ?? null;
            $action = $r->getAttribute('_action') ?? null;
            $uri = (string)($r->getUri() ?? '');
            $mem = round(memory_get_usage(true)/1048576,2);
            $peak = round(memory_get_peak_usage(true)/1048576,2);
            $pieces = [
                '[AgaviPipeline]', get_class($e), $e->getMessage(), '@', $first,
                'uri='.$uri,
                $module?"module=$module":null,
                $action?"action=$action":null,
                $route?"route=$route":null,
                $lastSql?('lastSql='.preg_replace('/\s+/', ' ', substr($lastSql,0,300))):null,
                $queryCount!==null?"qCount=$queryCount":null,
                "mem={$mem}MB peak={$peak}MB",
                'trace='.$snippet
            ];
            $msg = implode(' ', array_filter($pieces, fn($p)=>$p!==null && $p!==''));
            AgaviDebugLogger::debug($msg, $context);
        };
        return new class(new ErrorHandlingMiddleware($loggerFn), $handler) implements RequestHandlerInterface {
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
