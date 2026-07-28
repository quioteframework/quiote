<?php

declare(strict_types=1);

namespace Quiote\Runtime\Swoole;

use Quiote\Config\Config;
use Quiote\Runtime\Worker\WorkerLoop;
use Quiote\Runtime\Worker\WorkerRuntimeCapabilities;
use Quiote\Runtime\Worker\WorkerRuntimeInterface;
use RuntimeException;
use Swoole\Http\Request as SwooleHttpRequest;
use Swoole\Http\Response as SwooleHttpResponse;
use Swoole\Http\Server as SwooleHttpServer;
use Throwable;

/**
 * Serves requests from an embedded Swoole HTTP server.
 *
 * Two things make Swoole different from the other hosts:
 *
 * 1. It forks its worker processes when start() is called, i.e. *after* the app
 *    has bootstrapped and possibly opened database connections. Every child
 *    would inherit the same sockets and interleave on the wire, so
 *    {@see WorkerLoop::bootWorker()} runs on `workerStart` -- that is what
 *    `forksWorkers: true` in the capabilities is for.
 *
 * 2. Coroutines are switched off, deliberately. Quiote keeps process-global
 *    state (Config, Context, PluginManager, RoutingCallbackPool, LogContext,
 *    $_SESSION, the hydrated superglobals), so two requests interleaving inside
 *    one process would cross-contaminate all of it -- log lines attributed to
 *    the wrong user, session data leaking between requests. SWOOLE_BASE with
 *    `enable_coroutine => false` gives exactly the same one-request-at-a-time
 *    semantics as FrankenPHP and RoadRunner.
 *
 * Detection requires an explicit opt-in via $QUIOTE_WORKER_RUNTIME, unlike
 * RoadRunner: see {@see isSupported()}.
 */
final class SwooleRuntime implements WorkerRuntimeInterface
{
    public const DEFAULT_HOST = '0.0.0.0';
    public const DEFAULT_PORT = 8080;

    public function __construct(private readonly ?SwooleServerFactory $serverFactory = null)
    {
    }

    /**
     * `extension_loaded('swoole')` alone would be wrong: the extension is
     * routinely loaded under php-fpm, and claiming the process on that basis
     * would hijack every FPM request on such a box. Only the server itself knows
     * it is the server, and it has no environment marker of its own -- so the
     * operator says so.
     */
    public static function isSupported(): bool
    {
        return extension_loaded('swoole')
            && PHP_SAPI === 'cli'
            && getenv('QUIOTE_WORKER_RUNTIME') === 'swoole';
    }

    public static function alias(): string
    {
        return 'swoole';
    }

    public static function detectionPriority(): int
    {
        return 100;
    }

    public function capabilities(): WorkerRuntimeCapabilities
    {
        return new WorkerRuntimeCapabilities(
            persistent: true,
            populatesSuperglobals: false,
            sapiOutput: false,
            streaming: true,
            forksWorkers: true,
        );
    }

    public function run(WorkerLoop $loop): void
    {
        $settings = self::settings();
        $converter = new SwooleRequestConverter(self::converterOptions());

        $server = ($this->serverFactory ?? new NativeSwooleServerFactory())->create(
            Config::getString('worker.swoole.host', self::DEFAULT_HOST),
            (int) Config::getInt('worker.swoole.port', self::DEFAULT_PORT),
            $settings,
        );

        // Every worker child runs this once, right after the fork, before it sees
        // a request.
        $server->onWorkerStart(static function () use ($loop): void {
            $loop->bootWorker();
        });

        $server->onRequest(static function (
            SwooleHttpRequest $swooleRequest,
            SwooleHttpResponse $swooleResponse,
        ) use ($loop, $converter): void {
            $emitter = new SwooleResponseEmitter(new SwooleResponseWriter($swooleResponse));
            try {
                $request = $converter->toPsr7(SwooleRequestSnapshotFactory::fromSwoole($swooleRequest));
                // handle() never throws, so anything caught here came from the
                // conversion or from writing the response back out.
                $emitter->emit($loop->handle($request));
            } catch (Throwable $e) {
                $emitter->emit($loop->renderError($e));
            } finally {
                $loop->afterRequest();
            }
        });

        $server->start();
        $loop->shutdown();
    }

    /**
     * @return array<string, mixed>
     * @throws RuntimeException when coroutines are enabled without an explicit override.
     */
    public static function settings(): array
    {
        $coroutines = Config::getBool('worker.swoole.enable_coroutine', false);
        if ($coroutines && !Config::getBool('worker.swoole.allow_coroutine_unsafe', false)) {
            throw new RuntimeException(
                'worker.swoole.enable_coroutine is on, but Quiote is not coroutine-safe: Config, Context, '
                . 'PluginManager, RoutingCallbackPool, LogContext, $_SESSION and the hydrated superglobals are all '
                . 'process-global, so two requests interleaving in one worker would corrupt each other (log lines '
                . 'attributed to the wrong request, session data leaking between users). Scale with '
                . 'worker.swoole.worker_num instead. If you genuinely know your app is safe, set '
                . 'worker.swoole.allow_coroutine_unsafe to true.'
            );
        }

        $settings = [
            'enable_coroutine' => $coroutines,
            'worker_num' => max(1, (int) Config::getInt('worker.swoole.worker_num', 1)),
            // Swoole recycles a worker itself after this many requests, which is
            // why core.worker.max_requests should stay 0 here.
            'max_request' => max(0, (int) Config::getInt('worker.swoole.max_request', 0)),
            // Swoole's default silently truncates a larger body.
            'package_max_length' => max(1024, (int) Config::getInt('worker.swoole.package_max_length', 8 * 1024 * 1024)),
        ];

        return $settings;
    }

    private static function converterOptions(): SwooleConverterOptions
    {
        return new SwooleConverterOptions(
            scriptName: Config::getString('worker.swoole.script_name', '/index.php'),
            https: Config::getBool('worker.swoole.ssl', false),
        );
    }
}
