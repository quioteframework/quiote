<?php

namespace Agavi\Runtime;

use Agavi\Agavi;
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
                $request = $this->buildRequestFromGlobals($context);
                $response = $context->handlePsr($request);
                $emitter->emit($response);
            } catch (\Throwable $e) {
                $details = $e->getMessage();
                $ctxName = $context->getName();
                $reqClass = 'null';
                try {
                    $req = (new \ReflectionClass($context))->getProperty('request');
                    $req->setAccessible(true);
                    $rVal = $req->getValue($context);
                    $reqClass = $rVal ? get_class($rVal) : 'null';
                } catch (\Throwable) {
                }
                $factoryInfo = method_exists($context, 'getFactoryInfo') ? $context->getFactoryInfo('request') : null;
                $debugLine = 'Agavi request error [' . $ctxName . '] requestClass=' . $reqClass . ' captured=' . ($context->getController() ? 'yes' : 'no') . ' msg=' . $details;
                error_log($debugLine . "\nStack: " . $e->getTraceAsString());
                if (headers_sent() === false) {
                    http_response_code(500);
                }
                // Emit minimal plaintext so Caddy log still shows size 21 but browser displays clue
                echo 'Internal Server Error: ' . $e->getMessage();
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

        if (!\Agavi\Config\AgaviConfig::has('core.default_context')) {
            AgaviConfig::set('core.default_context', $this->contextName, true, true);
        }

        // 5. APCu config cache flag
        if (!defined('AGAVI_USE_APCU_CONFIG_CACHE')) {
            define('AGAVI_USE_APCU_CONFIG_CACHE', function_exists('apcu_fetch'));
        }

        // 6. Bootstrap (prewarm only if requested or option set)
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

    private function buildRequestFromGlobals(\Agavi\AgaviContext $context): \Psr\Http\Message\ServerRequestInterface
    {
        // New implementation: build a spec-compliant ServerRequest via PSR-17/ServerRequestCreator
        // Rationale: remove ad-hoc parsing (JSON, headers, etc.) from kernel; delegate body parsing to middleware.
        // Transitional phase: still wrap data in legacy adapter so existing middleware (e.g. AssetAggregation) functions.
        $legacyReq = $context->getRequest();
        // Use Nyholm server request creator (installed via nyholm/psr7-server)
        static $creator = null;
        if ($creator === null) {
            $psr17 = new \Nyholm\Psr7\Factory\Psr17Factory();
            $creator = new \Nyholm\Psr7Server\ServerRequestCreator($psr17, $psr17, $psr17, $psr17);
        }
        $base = $creator->fromGlobals(); // Does NOT parse JSON/form bodies; that's middleware's job.

        // Bridge direction (Phase 1): adapt spec request -> legacy adapter for downstream code.
        /** @var \Agavi\Request\AgaviRequest $legacyReqTyped */
        $legacyReqTyped = $legacyReq;
        $parsed = $base->getParsedBody();
        $parsedArray = is_array($parsed) ? $parsed : [];
        return new \Agavi\Http\PsrServerRequestAdapter(
            $legacyReqTyped,
            $base->getUri(),
            $base->getMethod(),
            $base->getBody(),
            $base->getServerParams(),
            $base->getHeaders(),
            $base->getCookieParams(),
            $base->getQueryParams(),
            $parsedArray,
            $base->getUploadedFiles()
        );
    }
}
