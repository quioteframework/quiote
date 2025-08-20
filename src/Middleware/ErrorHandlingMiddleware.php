<?php
namespace Agavi\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Nyholm\Psr7\Response;
use Agavi\Execution\ExecutionState;
use Throwable;
use Agavi\Config\AgaviConfig;

/**
 * Catches unhandled throwables from downstream middleware/action dispatch and
 * produces a generic 500 (or mapped) response. Minimal for Phase 2; can be
 * extended to perform content negotiation (HTML/JSON) and structured logging.
 */
class ErrorHandlingMiddleware implements MiddlewareInterface
{
    /** @var callable|null */
    private $logger;

    /** @param callable(Throwable $e, ServerRequestInterface $r):void|null $logger */
    public function __construct(?callable $logger = null)
    { $this->logger = $logger; }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch(Throwable $e) {
            if($this->logger) { try { ($this->logger)($e, $request); } catch(Throwable) { /* ignore */ } }
            // Build exception chain array (outer -> root cause)
            $exceptions = [];
            $cur = $e; while($cur) { $exceptions[] = $cur; $cur = $cur->getPrevious(); }

            // Persist chain as request attribute for later diagnostics or tests
            $request = $request->withAttribute('exceptions', $exceptions)->withAttribute('exception', $e);

            $status = 500;
            $map = [ \InvalidArgumentException::class => 400, \DomainException::class => 422 ];
            foreach($map as $cls => $code) { if($e instanceof $cls) { $status = $code; break; } }

            // Select template: explicit config override or default; fallback precedence shiny > simple > plaintext
            $tplFile = $this->resolveTemplateFile($request);
            // Variables expected by legacy templates: $e (root), $exceptions, $context, $container (null now)
            $context = $this->extractContext($request);
            ob_start();
            try { $container = null; /** @noinspection PhpUnusedLocalVariableInspection */ include $tplFile; } catch(Throwable $renderErr) {
                // Fallback simple plaintext if template fails
                if(getenv('AGAVI_DEBUG')) { $msg = "Template render failed: ".$renderErr->getMessage(); } else { $msg = 'Internal Server Error'; }
                ob_end_clean();
                return new Response($status, ['Content-Type'=>'text/plain; charset=utf-8','X-Agavi-Error-Type'=>$e::class], $msg); }
            $body = ob_get_clean();
            // Ensure content-type header present (templates echo headers themselves; if they did not, set one)
            $headers = [];
            if(!headers_sent()) { $headers['Content-Type'] = (str_contains($tplFile,'plaintext')?'text/plain':'text/html').'; charset=utf-8'; }
            $headers['X-Agavi-Error-Type'] = $e::class;
            return new Response($status, $headers, $body);
        }
    }

    private function extractContext(ServerRequestInterface $request): ?\Agavi\AgaviContext
    {
        // Attempt to recover context via global static or attribute on request
        // For now, rely on AgaviContext::getInstance() default profile.
        try { return \Agavi\AgaviContext::getInstance(); } catch(\Throwable) { return null; }
    }

    private function resolveTemplateFile(ServerRequestInterface $request): string
    {
        $accept = $request->getHeaderLine('Accept');
        $outputType = $request->getAttribute('output_type') ?? 'html';
        $forcePlain = str_contains($accept, 'text/plain') || $outputType === 'txt';
        $agaviDir = AgaviConfig::get('core.agavi_dir') ?: (__DIR__ . '/../..');
        $baseDir = rtrim($agaviDir, '/').'/Exception/templates';
        $candidates = [];
        if($forcePlain) {
            $candidates[] = $baseDir.'/plaintext.php';
        } else {
            // context-specific template first
            $ctxName = null;
            try { $ctxName = \Agavi\AgaviContext::getInstance()?->getName(); } catch(\Throwable) {}
            if($ctxName) {
                $key = 'exception.templates.' . $ctxName;
                if(AgaviConfig::has($key)) { $candidates[] = AgaviConfig::get($key); }
            }
            // configured default
            if(AgaviConfig::has('exception.default_template')) {
                $def = AgaviConfig::get('exception.default_template');
                if(is_string($def) && str_ends_with(strtolower($def), '.php')) {
                    $candidates[] = $def;
                } elseif(is_string($def)) {
                    $name = preg_replace('/\\.php$/i','',$def);
                    $candidates[] = $baseDir.'/'.$name.'.php';
                }
            }
            // fallbacks
            foreach(['shiny','simple','plaintext'] as $name) { $candidates[] = $baseDir.'/'.$name.'.php'; }
        }
        foreach($candidates as $p) { if(is_string($p) && is_file($p) && is_readable($p)) { return $p; } }
        return $baseDir.'/plaintext.php';
    }
}
