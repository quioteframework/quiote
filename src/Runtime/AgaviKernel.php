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
    private function __construct(
        private string $env,
        private string $contextName,
        private bool $usePsr,
        private string $rootDir,
    ) {}

    public static function create(): self
    {
        $root = dirname(__DIR__, 2); // src/Runtime/..
        $env = getenv('AGAVI_ENV') ?: 'prod';
        $context = getenv('AGAVI_CONTEXT') ?: 'web';
        $usePsr = (bool) getenv('AGAVI_PSR_HTTP');
        return new self($env, $context, $usePsr, $root);
    }

    public function run(): void
    {
        $this->bootstrap();
        $context = Agavi::context($this->contextName, true);
        $adapter = $this->selectWorkerAdapter();
        $emitter = new HttpEmitter();

        $handle = function() use ($context, $emitter) {
            try {
                if($this->usePsr) {
                    $pipeline = new PsrPipelineBuilder($context);
                    $request = $pipeline->buildRequestFromGlobals();
                    $dispatcher = $pipeline->buildDispatcher($pipeline->defaultFinalHandler());
                    $response = $dispatcher->handle($request);
                    $emitter->emit($response);
                } else {
                    $context->getController()->dispatch();
                }
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
        $appDir = getenv('AGAVI_APP_DIR') ?: ($this->rootDir . '/app');
        AgaviConfig::set('core.app_dir', $appDir, true, true);

        $vendorAutoload = $this->rootDir . '/vendor/autoload.php';
        if(is_readable($vendorAutoload)) { require_once $vendorAutoload; }

        if(!defined('AGAVI_USE_APCU_CONFIG_CACHE')) {
            define('AGAVI_USE_APCU_CONFIG_CACHE', function_exists('apcu_fetch'));
        }

        Agavi::bootstrap($this->env, $this->contextName, ['prewarm' => true]);
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
