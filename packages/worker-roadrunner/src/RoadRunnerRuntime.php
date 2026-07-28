<?php

declare(strict_types=1);

namespace Quiote\Runtime\RoadRunner;

use Nyholm\Psr7\Factory\Psr17Factory;
use Quiote\Config\Config;
use Quiote\Logging\Log;
use Quiote\Runtime\Emitter\ResponseEmitterInterface;
use Quiote\Runtime\Worker\WorkerLoop;
use Quiote\Runtime\Worker\WorkerRuntimeCapabilities;
use Quiote\Runtime\Worker\WorkerRuntimeInterface;
use Spiral\RoadRunner\Environment\Mode;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Http\PSR7WorkerInterface;
use Spiral\RoadRunner\Worker;
use Throwable;

/**
 * Serves requests as a RoadRunner PSR-7 worker.
 *
 * RoadRunner runs the worker under the CLI SAPI and speaks a protocol over the
 * process's own pipes, which makes it the first host where leaving the SAPI
 * actually bites: header() is a no-op, echo lands on the protocol channel, and
 * superglobals are never populated. {@see WorkerLoop} handles all of that off
 * the capabilities below -- this class only has to move requests and responses.
 *
 * Worker recycling is deliberately left to the server (`http.pool.max_jobs` in
 * .rr.yaml): stopping the loop from PHP mid-pool looks like a crashed worker to
 * RoadRunner, so `core.worker.max_requests` should stay at its default of 0.
 */
final class RoadRunnerRuntime implements WorkerRuntimeInterface
{
    private ?PSR7WorkerInterface $worker = null;
    private ?ResponseEmitterInterface $emitter = null;

    /**
     * Both collaborators are injectable so the loop can be driven from a test
     * without a RoadRunner server on the other end of the relay.
     */
    public function __construct(?PSR7WorkerInterface $worker = null, ?ResponseEmitterInterface $emitter = null)
    {
        $this->worker = $worker;
        $this->emitter = $emitter;
    }

    /**
     * $RR_MODE is set by the RoadRunner server itself when it spawns a worker,
     * so unlike an extension being merely loaded it is real evidence about how
     * this process is being hosted -- which is why this needs no opt-in.
     */
    public static function isSupported(): bool
    {
        return getenv('RR_MODE') === Mode::MODE_HTTP;
    }

    public static function alias(): string
    {
        return 'roadrunner';
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
            // RoadRunner spawns each worker as its own process running this
            // script from the top, so there is no post-bootstrap fork to repair.
            forksWorkers: false,
        );
    }

    public function run(WorkerLoop $loop): void
    {
        $worker = $this->worker ??= self::createWorker();
        $emitter = $this->emitter ??= self::createEmitter($worker);

        $loop->bootWorker();

        while ($loop->shouldContinue()) {
            try {
                $request = $worker->waitRequest();
            } catch (Throwable $e) {
                // A payload we couldn't even parse: report it and keep serving,
                // rather than losing the worker over one malformed request.
                $this->reportToServer($worker, $e);
                continue;
            }

            if ($request === null) {
                break; // the server asked us to stop
            }

            try {
                // handle() never throws, so this only guards emission itself.
                $emitter->emit($loop->handle($request));
            } catch (Throwable $e) {
                $this->reportToServer($worker, $e);
            } finally {
                $loop->afterRequest();
            }
        }

        $loop->shutdown();
    }

    private function reportToServer(PSR7WorkerInterface $worker, Throwable $e): void
    {
        try {
            $worker->getWorker()->error((string) $e);
        } catch (Throwable $reportFailure) {
            // The relay itself is gone; the log is the only place left to say so.
            Log::for($this)->error(
                '[RoadRunnerRuntime] could not report an error to the server: ' . $reportFailure->getMessage()
                . ' (original: ' . $e->getMessage() . ')'
            );
        }
    }

    private static function createWorker(): PSR7Worker
    {
        $psr17 = new Psr17Factory();

        return new PSR7Worker(Worker::create(), $psr17, $psr17, $psr17);
    }

    private static function createEmitter(PSR7WorkerInterface $worker): ResponseEmitterInterface
    {
        if (!$worker instanceof PSR7Worker) {
            throw new \RuntimeException(sprintf(
                'A custom %s was supplied without a matching emitter. Pass both to %s::__construct(), '
                . 'since the default emitter needs %s\'s own streaming API.',
                PSR7WorkerInterface::class,
                self::class,
                PSR7Worker::class,
            ));
        }

        return new RoadRunnerResponseEmitter(
            $worker,
            Config::getInt('worker.roadrunner.chunk_size', RoadRunnerResponseEmitter::DEFAULT_CHUNK_SIZE),
        );
    }
}
