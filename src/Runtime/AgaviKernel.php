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
    private array $autoloadPaths = [];
    private ?string $appDir = null;
    private bool $prewarm = false;
    private array $extraContexts = [];

    private function __construct(
        private string $env,
        private string $contextName,
        private string $rootDir,
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
        $root = dirname(__DIR__, 2);
        $env = $options['env'] ?? getenv('AGAVI_ENV') ?: 'prod';
        $context = $options['context'] ?? getenv('AGAVI_CONTEXT') ?: 'web';
        $kernel = new self($env, $context, $root);
        if(isset($options['app_dir'])) { $kernel->appDir = $options['app_dir']; }
        if(isset($options['prewarm'])) { $kernel->prewarm = (bool)$options['prewarm']; }
        if(isset($options['contexts']) && is_array($options['contexts'])) { $kernel->extraContexts = $options['contexts']; }
        return $kernel;
    }

    public function run(): void
    {
        $this->bootstrap();
        $context = Agavi::context($this->contextName, true);
        $adapter = $this->selectWorkerAdapter();
        $emitter = new HttpEmitter();

        $handle = function() use ($context, $emitter) {
            try {
                $pipeline = new PsrPipelineBuilder($context);
                $request = $pipeline->buildRequestFromGlobals();
                $dispatcher = $pipeline->buildDispatcher($pipeline->defaultFinalHandler());
                $response = $dispatcher->handle($request);
                $emitter->emit($response);
            } catch (\Throwable $e) {
                error_log('Agavi request error: '.$e->getMessage());
                if(headers_sent() === false) { http_response_code(500); }
                echo 'Internal Server Error';
            }
            return true; // continue loop
        };

        $reset = function() use ($context) {
            if(class_exists(AgaviWorkerManager::class)) {
                AgaviWorkerManager::resetForNextRequest($context->getName());
            }
        };

        $adapter->run($handle, $reset);
    }

    private function bootstrap(): void
    {
        // 2. Load library (framework) vendor autoload if not already loaded (Composer class missing) and not explicitly provided
        if(!class_exists('Composer\\Autoload\\ClassLoader')) {
            $frameworkAutoload = $this->rootDir . '/vendor/autoload.php';
            if(is_readable($frameworkAutoload)) { require_once $frameworkAutoload; }
        }

        // 3. Determine application directory
        $appDir = $this->appDir
            ?? getenv('AGAVI_APP_DIR')
            ?: ($this->rootDir . '/app');
        AgaviConfig::set('core.app_dir', $appDir, true, true);

        // 4. Default context early (if caller provided via env/option)
        $defaultContext = getenv('AGAVI_DEFAULT_CONTEXT') ?: $this->contextName;
        if(!\Agavi\Config\AgaviConfig::has('core.default_context')) {
            AgaviConfig::set('core.default_context', $defaultContext, true, true);
        }

        // 5. APCu config cache flag
        if(!defined('AGAVI_USE_APCU_CONFIG_CACHE')) {
            define('AGAVI_USE_APCU_CONFIG_CACHE', function_exists('apcu_fetch'));
        }

        // 6. Bootstrap (prewarm only if requested or option set)
        $prewarmOpt = $this->prewarm || in_array(strtolower((string)getenv('AGAVI_APCU_PREWARM')), ['1','true','yes','on'], true);
        $contextsToPreCreate = array_unique(array_filter(array_merge([$this->contextName], $this->extraContexts)));
        Agavi::bootstrap($this->env, $contextsToPreCreate, ['prewarm' => $prewarmOpt]);
    }

    private function selectWorkerAdapter(): WorkerAdapterInterface
    {
        if(FrankenPhpWorkerAdapter::isSupported()) {
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
}
