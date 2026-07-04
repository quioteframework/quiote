<?php

namespace Quiote\Telemetry\Dashboard;

use Quiote\Logging\Log;
use Revolt\EventLoop;

/**
 * A minimal OTLP/HTTP receiver: binds a TCP socket and services it on the
 * global Revolt event loop, exactly the pattern `symfony/tui`'s own
 * `Terminal::start()` uses for STDIN (`EventLoop::onReadable()`) -- so this
 * runs cooperatively alongside a `Tui::run()` loop in the same process, no
 * threads or second process needed.
 *
 * Deliberately not a general HTTP server: it accepts exactly `POST
 * /v1/traces` and `POST /v1/metrics`, the two paths the OTel PHP OTLP/HTTP
 * exporter sends, decodes the body via {@see OtlpDecoder}, and replies `200`
 * with an empty body (the exporter only checks the status code, never the
 * response body -- see `OpenTelemetry\SDK\Common\Export\Http\PsrTransport`).
 * Everything else gets `400` and the connection is closed; a decode/parse
 * failure never propagates past this class -- the receiver logs it and keeps
 * serving every other connection, mirroring the "never crash the request"
 * posture the telemetry middleware holds on the app side.
 *
 * One connection per request (no keep-alive) -- simplest correct thing, and
 * cheap enough at dashboard-demo request volumes; see the plan doc for the
 * keep-alive note as a later optimization for worker-mode batch bursts.
 */
final class OtlpReceiver
{
    private const PATH_TRACES = '/v1/traces';
    private const PATH_METRICS = '/v1/metrics';

    /** @var resource|null */
    private $server = null;

    private ?string $acceptWatcherId = null;

    /** @var array<int,string> connection-id => readable-watcher-id */
    private array $connectionWatchers = [];

    /** @var array<int,HttpMessageParser> connection-id => its parser */
    private array $parsers = [];

    /**
     * @param callable(ReceivedSpan[]): void $onSpans
     * @param callable(ReceivedMetric[]): void $onMetrics
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly OtlpDecoder $decoder,
        private $onSpans,
        private $onMetrics,
    ) {
    }

    public function start(): void
    {
        $address = sprintf('tcp://%s:%d', $this->host, $this->port);
        $server = @stream_socket_server($address, $errno, $errstr);
        if ($server === false) {
            throw new \RuntimeException(sprintf('Could not bind OTLP receiver on %s: %s', $address, $errstr));
        }

        stream_set_blocking($server, false);
        $this->server = $server;

        $this->acceptWatcherId = EventLoop::onReadable($server, function (): void {
            $this->acceptConnection();
        });
    }

    public function stop(): void
    {
        if ($this->acceptWatcherId !== null) {
            EventLoop::cancel($this->acceptWatcherId);
            $this->acceptWatcherId = null;
        }

        foreach ($this->connectionWatchers as $watcherId) {
            EventLoop::cancel($watcherId);
        }
        $this->connectionWatchers = [];
        $this->parsers = [];

        if ($this->server !== null) {
            fclose($this->server);
            $this->server = null;
        }
    }

    public function endpoint(): string
    {
        return sprintf('http://%s:%d', $this->host, $this->boundPort());
    }

    /** The actual bound port -- useful when constructed with port 0 (OS-assigned), e.g. in tests. */
    public function boundPort(): int
    {
        $name = stream_socket_get_name($this->server, false);
        $colon = strrpos($name, ':');

        return (int) substr($name, $colon + 1);
    }

    private function acceptConnection(): void
    {
        $connection = @stream_socket_accept($this->server, 0);
        if ($connection === false) {
            return;
        }

        stream_set_blocking($connection, false);
        $id = (int) $connection;
        $this->parsers[$id] = new HttpMessageParser();

        $this->connectionWatchers[$id] = EventLoop::onReadable($connection, function () use ($connection, $id): void {
            $this->readConnection($connection, $id);
        });
    }

    /** @param resource $connection */
    private function readConnection($connection, int $id): void
    {
        $data = @fread($connection, 65536);

        if ($data === false || $data === '') {
            $this->closeConnection($connection, $id);
            return;
        }

        try {
            $this->parsers[$id]->feed($data);
            $request = $this->parsers[$id]->tryParse();
        } catch (MalformedRequestException $e) {
            $this->logFailure('parse', $e);
            $this->respond($connection, 400);
            $this->closeConnection($connection, $id);
            return;
        }

        if ($request === null) {
            return;
        }

        $this->handleRequest($connection, $request);
        $this->closeConnection($connection, $id);
    }

    private function handleRequest($connection, ParsedHttpRequest $request): void
    {
        if ($request->method !== 'POST') {
            $this->respond($connection, 400);
            return;
        }

        $contentType = $request->header('content-type') ?? 'application/x-protobuf';

        try {
            match ($request->path) {
                self::PATH_TRACES => ($this->onSpans)($this->decoder->decodeTraces($request->body, $contentType)),
                self::PATH_METRICS => ($this->onMetrics)($this->decoder->decodeMetrics($request->body, $contentType)),
                default => throw new MalformedRequestException(sprintf('Unknown OTLP path "%s".', $request->path)),
            };
        } catch (MalformedRequestException $e) {
            $this->logFailure('decode', $e);
            $this->respond($connection, 400);
            return;
        }

        $this->respond($connection, 200);
    }

    /** @param resource $connection */
    private function respond($connection, int $status): void
    {
        $reason = $status === 200 ? 'OK' : 'Bad Request';
        $response = sprintf("HTTP/1.1 %d %s\r\nContent-Length: 0\r\nConnection: close\r\n\r\n", $status, $reason);
        @fwrite($connection, $response);
    }

    /** @param resource $connection */
    private function closeConnection($connection, int $id): void
    {
        if (isset($this->connectionWatchers[$id])) {
            EventLoop::cancel($this->connectionWatchers[$id]);
            unset($this->connectionWatchers[$id]);
        }
        unset($this->parsers[$id]);
        @fclose($connection);
    }

    private function logFailure(string $stage, \Throwable $e): void
    {
        Log::for(self::class)->debug(sprintf('[OtlpReceiver] %s failed: %s: %s', $stage, $e::class, $e->getMessage()));
    }
}
