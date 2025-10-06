<?php

namespace Agavi\Runtime;

use Agavi\Agavi;
use Nyholm\Psr7\Response;
use Agavi\Middleware\ErrorHandlingMiddleware;
use Agavi\Request\AgaviWebRequest;
use Agavi\Logging\AgaviDebugLogger;
use Agavi\Config\AgaviConfig;
use Agavi\Util\AgaviWorkerManager;
use Agavi\Runtime\Worker\FrankenPhpWorkerAdapter;
use Agavi\Runtime\Worker\SingleRequestAdapter;
use Agavi\Runtime\Worker\WorkerAdapterInterface;

class AgaviKernel
{
    private ?string $appDir = null;
    private bool $prewarm = false;
    private array $extraContexts = [];

    private function __construct(
        private string $env,
        private string $contextName,
    ) {}

    /**
     * Create kernel with optional overrides.
     * Options:
     *  - env: string environment
     *  - context: string primary context name
     *  - psr: bool enable PSR pipeline
     *  - app_dir: string application root (contains Config/, Modules/, etc.)
     *  - autoload_paths: string|array additional composer autoload files to require (app first)
     *  - prewarm: bool force prewarm
     *  - contexts: array additional contexts to pre-create
     */
    public static function create(array $options = []): self
    {

        $env = $options['env'] ?? getenv('AGAVI_ENV') ?: 'prod';
        $context = $options['context'] ?? getenv('AGAVI_CONTEXT') ?: 'web';
        $kernel = new self($env, $context);
        if (isset($options['app_dir'])) {
            $kernel->appDir = $options['app_dir'];
        }
        if (isset($options['prewarm'])) {
            $kernel->prewarm = (bool)$options['prewarm'];
        }
        if (isset($options['contexts']) && is_array($options['contexts'])) {
            $kernel->extraContexts = $options['contexts'];
        }
        return $kernel;
    }

    public function run(): void
    {
        $this->bootstrap();
        $context = Agavi::context($this->contextName, true);
        $adapter = $this->selectWorkerAdapter();
        $emitter = new HttpEmitter();

        $handle = function () use ($context, $emitter) {
            try {
                $request = $this->buildRequestFromGlobals();
                $response = $context->handle($request);
                $emitter->emit($response);
            } catch (\Throwable $e) {
                // Log basic diagnostics
                AgaviDebugLogger::debug('[AgaviKernel] Uncaught during handle bootstrap: '.get_class($e).': '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine(), $context);
                // Attempt unified error rendering via ErrorHandlingMiddleware helper.
                try {
                    $err = new ErrorHandlingMiddleware(function(\Throwable $ex, \Psr\Http\Message\ServerRequestInterface $r) use ($context) {
                        AgaviDebugLogger::debug('[AgaviKernel][late] '.get_class($ex).': '.$ex->getMessage(), $context);
                    });
                    // If original PSR request not built (rare), synthesize minimal one.
                    if(!isset($request) || !$request) {
                        $psr17 = new \Nyholm\Psr7\Factory\Psr17Factory();
                        $request = $psr17->createServerRequest('GET', '/error');
                    }
                    $resp = $err->renderExceptionResponse($request, $e);
                    $emitter->emit($resp);
                } catch(\Throwable $renderFail) {
                    if (!headers_sent()) { header('Content-Type: text/plain; charset=utf-8', true, 500); }
                    echo 'Internal Server Error';
                }
            }
            return true; // continue loop
        };

        $reset = function () use ($context) {
            if (class_exists(AgaviWorkerManager::class)) {
                AgaviWorkerManager::resetForNextRequest($context->getName());
            }
        };

        $adapter->run($handle, $reset);
    }

    private function bootstrap(): void
    {
        AgaviConfig::set('core.app_dir', $this->appDir, true, true);

        if (!AgaviConfig::has('core.default_context')) {
            AgaviConfig::set('core.default_context', $this->contextName, true, true);
        }

        // If APCu exists but has not been explicitly disabled
        // enable APCu
        if (!defined('AGAVI_USE_APCU_CONFIG_CACHE')) {
            define('AGAVI_USE_APCU_CONFIG_CACHE', function_exists('apcu_fetch'));
        }

        // Bootstrap (prewarm only if requested or option set)
        $contextsToPreCreate = array_unique(array_filter(array_merge([$this->contextName], $this->extraContexts)));
        Agavi::bootstrap($this->env, $contextsToPreCreate, ['prewarm' => $this->prewarm]);
    }

    private function selectWorkerAdapter(): WorkerAdapterInterface
    {
        if (FrankenPhpWorkerAdapter::isSupported()) {
            AgaviWorkerManager::configure([
                'max_requests_before_cleanup' => (int)(getenv('AGAVI_MAX_REQUESTS') ?: 1000),
                'preserve_route_cache' => true,
                'preserve_route_trie' => true,
                'preserve_callback_pool' => true,
                'reset_stats' => true,
            ]);
            return new FrankenPhpWorkerAdapter($this->contextName);
        }
        return new SingleRequestAdapter();
    }

    private function buildRequestFromGlobals(): \Psr\Http\Message\ServerRequestInterface
    {
        // Build a spec-compliant ServerRequest via Nyholm factories, then wrap directly in AgaviWebRequest.
        static $creator = null;
        if ($creator === null) {
            $psr17 = new \Nyholm\Psr7\Factory\Psr17Factory();
            $creator = new \Nyholm\Psr7Server\ServerRequestCreator($psr17, $psr17, $psr17, $psr17);
        }
        $base = $creator->fromGlobals(); // Body parsing/JSON handled later by middleware.
        $agaviReq = new AgaviWebRequest();
        $agaviReq->attachPsrRequest($base);
        return $agaviReq;
    }
}
