<?php

declare(strict_types=1);

namespace Quiote\Runtime;

use Quiote\Config\Config;
use Quiote\Quiote;
use Quiote\Runtime\Request\WorkerRequestFactory;
use Quiote\Runtime\Session\NativeSessionCookieBridge;
use Quiote\Runtime\Superglobals\SuperglobalBridge;
use Quiote\Runtime\Worker\WorkerLoop;
use Quiote\Runtime\Worker\WorkerRuntimeInfo;
use Quiote\Runtime\Worker\WorkerRuntimeInterface;
use Quiote\Runtime\Worker\WorkerRuntimeRegistry;
use Quiote\Util\WorkerManager;
use RuntimeException;

/**
 * Boots the framework and hands the request loop to a worker runtime.
 *
 * The Kernel deliberately knows nothing about how requests arrive or responses
 * leave: that is the selected {@see WorkerRuntimeInterface}'s job, and
 * everything in between is {@see WorkerLoop}'s. Its own remit is the three
 * steps around that -- bootstrap, pick the runtime, start it.
 */
class Kernel
{
    private ?string $appDir = null;
    private bool $prewarm = false;
    /** @var array<int, string> */
    private array $extraContexts = [];
    private WorkerRuntimeInterface|string|null $runtimeOverride = null;

    private function __construct(
        private readonly string $env,
        private readonly string $contextName,
    ) {}

    /**
     * Create kernel with optional overrides.
     * Options:
     *  - env: string environment
     *  - context: string primary context name
     *  - app_dir: string application root (contains Config/, Modules/, etc.)
     *  - prewarm: bool force prewarm
     *  - contexts: array additional contexts to pre-create
     *  - worker_runtime: string alias ("frankenphp", "roadrunner", ...), a
     *    fully-qualified WorkerRuntimeInterface class name, or an instance.
     *    Takes precedence over $QUIOTE_WORKER_RUNTIME and `core.worker_runtime`.
     * @param array<string, mixed> $options
     */
    public static function create(array $options = []): self
    {
        $env = $options['env'] ?? getenv('QUIOTE_ENV') ?: 'prod';
        $context = $options['context'] ?? getenv('QUIOTE_CONTEXT') ?: 'web';
        $kernel = new self(is_string($env) ? $env : 'prod', is_string($context) ? $context : 'web');
        if (isset($options['app_dir']) && is_string($options['app_dir'])) {
            $kernel->appDir = $options['app_dir'];
        }
        if (isset($options['prewarm'])) {
            $kernel->prewarm = (bool)$options['prewarm'];
        }
        if (isset($options['contexts']) && is_array($options['contexts'])) {
            /** @var array<int, string> $contexts */
            $contexts = array_values(array_filter($options['contexts'], 'is_string'));
            $kernel->extraContexts = $contexts;
        }
        $runtime = $options['worker_runtime'] ?? null;
        if ($runtime instanceof WorkerRuntimeInterface || (is_string($runtime) && $runtime !== '')) {
            $kernel->runtimeOverride = $runtime;
        }
        return $kernel;
    }

    public function run(): void
    {
        $this->bootstrap();

        $context = Quiote::context($this->contextName, true);
        $runtime = $this->selectRuntime();
        WorkerRuntimeInfo::install($runtime);

        $capabilities = $runtime->capabilities();
        if ($capabilities->persistent) {
            WorkerManager::configure([
                'max_requests_before_cleanup' => $this->cleanupInterval(),
                'preserve_callback_pool' => true,
                'reset_stats' => true,
            ]);
        }

        $runtime->run(new WorkerLoop(
            context: $context,
            requestFactory: new WorkerRequestFactory(),
            superglobals: new SuperglobalBridge(),
            output: new OutputCapture(),
            errors: new ErrorResponseFactory(),
            sessionCookies: new NativeSessionCookieBridge(),
            capabilities: $capabilities,
            maxRequests: $capabilities->persistent ? $this->maxRequests() : 0,
        ));
    }

    private function bootstrap(): void
    {
        Config::set('core.app_dir', $this->appDir, true, true);

        if (!Config::has('core.default_context')) {
            Config::set('core.default_context', $this->contextName, true, true);
        }

        // If APCu exists AND is actually enabled for this SAPI, use it for the
        // config cache. function_exists() alone is not enough: the extension can
        // be loaded but disabled (e.g. apc.enable_cli=0 on the CLI), in which case
        // apcu_store()/apcu_fetch() silently no-op and the APCu cache path would
        // store nothing yet still report itself active. apcu_enabled() reflects the
        // real runtime state, matching the check APCuConfigCache uses.
        if (!defined('QUIOTE_USE_APCU_CONFIG_CACHE')) {
            define('QUIOTE_USE_APCU_CONFIG_CACHE', function_exists('apcu_enabled') && apcu_enabled());
        }

        // Bootstrap (prewarm only if requested or option set)
        $contextsToPreCreate = array_unique(array_filter(array_merge([$this->contextName], $this->extraContexts)));
        // Quiote::bootstrap() builds the worker-lifetime telemetry providers
        // itself, as a KernelBootEvent listener -- Kernel no longer
        // names TelemetryBootstrap directly.
        Quiote::bootstrap($this->env, $contextsToPreCreate, ['prewarm' => $this->prewarm]);
    }

    /**
     * Resolution order, highest first: the create() option, $QUIOTE_WORKER_RUNTIME,
     * `core.worker_runtime`, then auto-detection.
     *
     * An explicitly named runtime that doesn't claim this process is a hard
     * error rather than a fall back to `sapi`: silently downgrading a
     * production RoadRunner deployment to one-request-per-process is a far
     * worse outcome than refusing to start.
     */
    private function selectRuntime(): WorkerRuntimeInterface
    {
        if ($this->runtimeOverride instanceof WorkerRuntimeInterface) {
            return $this->runtimeOverride;
        }

        $requested = $this->runtimeOverride;
        $source = 'the "worker_runtime" kernel option';

        if ($requested === null) {
            $fromEnv = getenv('QUIOTE_WORKER_RUNTIME');
            if (is_string($fromEnv) && $fromEnv !== '') {
                $requested = $fromEnv;
                $source = '$QUIOTE_WORKER_RUNTIME';
            }
        }

        if ($requested === null) {
            $fromConfig = Config::getString('core.worker_runtime', 'auto');
            if ($fromConfig !== '' && $fromConfig !== 'auto') {
                $requested = $fromConfig;
                $source = 'the "core.worker_runtime" setting';
            }
        }

        if ($requested === null || $requested === 'auto') {
            $class = WorkerRuntimeRegistry::detect();
            return new $class();
        }

        $class = WorkerRuntimeRegistry::instantiateClassFor($requested);
        if (!$class::isSupported()) {
            throw new RuntimeException(sprintf(
                'Worker runtime "%s" was requested via %s, but it reports that it is not hosting this process. '
                . 'Check that the app is being started by that server (%s). Use "auto" to detect the runtime instead.',
                $requested,
                $source,
                self::detectionHintFor($class::alias()),
            ));
        }

        return new $class();
    }

    private static function detectionHintFor(string $alias): string
    {
        return match ($alias) {
            'frankenphp' => 'FrankenPHP worker mode defines frankenphp_handle_request()',
            'roadrunner' => 'RoadRunner sets $RR_MODE=http for its workers',
            'swoole' => 'the Swoole runtime needs ext-swoole, the CLI SAPI, and $QUIOTE_WORKER_RUNTIME=swoole',
            default => 'see that runtime\'s isSupported()',
        };
    }

    /**
     * How many requests one worker process handles before the loop stops and
     * lets the supervisor start a fresh one. 0 (the default) disables the
     * budget, which is what RoadRunner and Swoole want -- both recycle workers
     * themselves via max_jobs/max_request, and a PHP-side stop mid-pool looks
     * to them like a crashed worker.
     */
    private function maxRequests(): int
    {
        return max(0, (int) Config::getInt('core.worker.max_requests', 0));
    }

    /**
     * How often WorkerManager does its deep cleanup pass. $QUIOTE_MAX_REQUESTS
     * has always driven this (rather than terminating the loop) and keeps doing so.
     */
    private function cleanupInterval(): int
    {
        $fromEnv = getenv('QUIOTE_MAX_REQUESTS');
        if (is_string($fromEnv) && $fromEnv !== '' && is_numeric($fromEnv)) {
            return max(1, (int) $fromEnv);
        }
        return max(1, (int) Config::getInt('core.worker.cleanup_interval', 1000));
    }
}
