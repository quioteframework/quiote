<?php

declare(strict_types=1);

namespace Quiote\Runtime\Swoole;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Turns a {@see SwooleRequestSnapshot} into a PSR-7 request.
 *
 * Swoole hands over its own request shape rather than CGI-style server params,
 * so this is where the two are reconciled. Three details are easy to get wrong
 * and each breaks something specific:
 *
 *  - `$request->server` keys are **lowercase** (`request_method`, not
 *    `REQUEST_METHOD`), so everything reading CGI names sees nothing unless they
 *    are translated.
 *  - Swoole supplies no `SCRIPT_NAME`. `Quiote\Routing\Routing` reads it when
 *    generating URLs, so omitting it corrupts generated links rather than
 *    failing loudly. Hence {@see SwooleConverterOptions::$scriptName}.
 *  - `content-type`/`content-length` become bare `CONTENT_TYPE`/`CONTENT_LENGTH`
 *    without the `HTTP_` prefix, matching CGI --
 *    `Quiote\Request\WebRequest` reads `$_SERVER['CONTENT_TYPE']` directly.
 *
 * Reverse-proxy correction is deliberately *not* done here: every runtime funnels
 * through {@see \Quiote\Runtime\Request\WorkerRequestFactory}, which applies it
 * uniformly.
 */
final class SwooleRequestConverter
{
    public function __construct(
        private readonly SwooleConverterOptions $options = new SwooleConverterOptions(),
        private readonly Psr17Factory $psr17 = new Psr17Factory(),
    ) {
    }

    public function toPsr7(SwooleRequestSnapshot $snapshot): ServerRequestInterface
    {
        $method = strtoupper($snapshot->serverValue('request_method') ?? 'GET');
        $target = $this->requestTarget($snapshot);
        $serverParams = $this->serverParams($snapshot, $target);

        $request = $this->psr17
            ->createServerRequest($method, $this->uri($snapshot, $target), $serverParams)
            ->withProtocolVersion($this->protocolVersion($snapshot))
            ->withBody($this->psr17->createStream($snapshot->rawContent))
            ->withQueryParams($snapshot->get)
            ->withCookieParams($snapshot->cookie)
            ->withUploadedFiles($this->uploadedFiles($snapshot->files));

        foreach ($snapshot->header as $name => $value) {
            $request = $request->withHeader((string) $name, $value);
        }

        if ($snapshot->post !== []) {
            // Matches the SAPI path, where the PSR-7 factory derives parsedBody
            // from $_POST. A non-form body is left null on purpose so
            // middlewares/payload still parses JSON off the stream itself.
            $request = $request->withParsedBody($snapshot->post);
        }

        return $request;
    }

    /** The request target, with the query string re-attached if Swoole split it off. */
    private function requestTarget(SwooleRequestSnapshot $snapshot): string
    {
        $uri = $snapshot->serverValue('request_uri')
            ?? $snapshot->serverValue('path_info')
            ?? '/';
        $query = $snapshot->serverValue('query_string') ?? '';

        if ($query !== '' && !str_contains($uri, '?')) {
            $uri .= '?' . $query;
        }

        return $uri === '' ? '/' : $uri;
    }

    private function uri(SwooleRequestSnapshot $snapshot, string $target): string
    {
        $scheme = $this->options->https ? 'https' : 'http';
        $host = $snapshot->header['host'] ?? $snapshot->serverValue('server_addr') ?? 'localhost';

        return $scheme . '://' . $host . $target;
    }

    private function protocolVersion(SwooleRequestSnapshot $snapshot): string
    {
        $protocol = $snapshot->serverValue('server_protocol') ?? 'HTTP/1.1';
        $slash = strpos($protocol, '/');

        return $slash === false ? '1.1' : substr($protocol, $slash + 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function serverParams(SwooleRequestSnapshot $snapshot, string $target): array
    {
        $params = [
            'REQUEST_METHOD' => strtoupper($snapshot->serverValue('request_method') ?? 'GET'),
            'REQUEST_URI' => $target,
            'SERVER_PROTOCOL' => $snapshot->serverValue('server_protocol') ?? 'HTTP/1.1',
            'SCRIPT_NAME' => $this->options->scriptName,
            'PHP_SELF' => $this->options->scriptName,
            'QUERY_STRING' => $snapshot->serverValue('query_string') ?? '',
        ];

        foreach ([
            'remote_addr' => 'REMOTE_ADDR',
            'remote_port' => 'REMOTE_PORT',
            'server_port' => 'SERVER_PORT',
            'server_addr' => 'SERVER_ADDR',
            'path_info' => 'PATH_INFO',
        ] as $swooleKey => $cgiKey) {
            $value = $snapshot->serverValue($swooleKey);
            if ($value !== null) {
                $params[$cgiKey] = $value;
            }
        }

        // TelemetryMiddleware reads REQUEST_TIME_FLOAT to measure wall time, so
        // Swoole's own timings are preferred over "now" wherever available.
        $params['REQUEST_TIME'] = (int) ($snapshot->serverValue('request_time') ?? (string) time());
        $params['REQUEST_TIME_FLOAT'] = (float) ($snapshot->serverValue('request_time_float') ?? (string) microtime(true));

        foreach ($snapshot->header as $name => $value) {
            $key = strtoupper(str_replace('-', '_', (string) $name));
            $params[$key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH' ? $key : 'HTTP_' . $key] = $value;
        }

        $host = $snapshot->header['host'] ?? null;
        if ($host !== null) {
            $params['HTTP_HOST'] = $host;
            // Strip any port: SERVER_NAME is the bare name, unlike HTTP_HOST.
            $params['SERVER_NAME'] = self::hostWithoutPort($host);
        }

        if ($this->options->https) {
            $params['HTTPS'] = 'on';
            $params['REQUEST_SCHEME'] = 'https';
        } else {
            $params['REQUEST_SCHEME'] = 'http';
        }

        return $params;
    }

    private static function hostWithoutPort(string $host): string
    {
        if (str_starts_with($host, '[')) {
            // Bracketed IPv6 literal: the colons inside are part of the address.
            $close = strpos($host, ']');
            return $close === false ? $host : substr($host, 0, $close + 1);
        }
        $colon = strrpos($host, ':');

        return $colon === false ? $host : substr($host, 0, $colon);
    }

    /**
     * Swoole's $request->files is $_FILES-shaped, including the parallel-array
     * form PHP uses for `name="doc[]"`.
     *
     * The temp files Swoole writes are deleted when the request ends, so
     * moveTo() has to happen during the request -- there is no deferring an
     * upload to a queued job.
     *
     * @param array<string, mixed> $files
     * @return array<string, UploadedFileInterface|array<array-key, UploadedFileInterface>>
     */
    private function uploadedFiles(array $files): array
    {
        $normalised = [];
        foreach ($files as $field => $spec) {
            if (!is_array($spec)) {
                continue;
            }
            $entry = is_array($spec['name'] ?? null)
                ? $this->uploadedFileGroup($spec)
                : $this->uploadedFile($spec);
            if ($entry !== null) {
                $normalised[(string) $field] = $entry;
            }
        }

        return $normalised;
    }

    /**
     * @param array<array-key, mixed> $spec
     */
    private function uploadedFile(array $spec): ?UploadedFileInterface
    {
        $tmpName = $spec['tmp_name'] ?? null;
        if (!is_string($tmpName) || $tmpName === '') {
            return null;
        }

        $rawError = $spec['error'] ?? UPLOAD_ERR_OK;
        $error = is_numeric($rawError) ? (int) $rawError : UPLOAD_ERR_OK;

        $size = isset($spec['size']) && is_numeric($spec['size']) ? (int) $spec['size'] : null;
        $clientName = is_string($spec['name'] ?? null) ? $spec['name'] : null;
        $clientType = is_string($spec['type'] ?? null) ? $spec['type'] : null;

        // A failed upload has no readable temp file, so it is described rather
        // than opened -- opening it would fatal on a missing path.
        $stream = $error === UPLOAD_ERR_OK
            ? $this->psr17->createStreamFromFile($tmpName, 'r')
            : $this->psr17->createStream('');

        return $this->psr17->createUploadedFile($stream, $size, $error, $clientName, $clientType);
    }

    /**
     * @param array<array-key, mixed> $spec
     * @return array<array-key, UploadedFileInterface>
     */
    private function uploadedFileGroup(array $spec): array
    {
        $names = $spec['name'];
        if (!is_array($names)) {
            return [];
        }

        $group = [];
        foreach (array_keys($names) as $index) {
            $file = $this->uploadedFile([
                'name' => $this->groupValue($spec, 'name', $index),
                'type' => $this->groupValue($spec, 'type', $index),
                'tmp_name' => $this->groupValue($spec, 'tmp_name', $index),
                'error' => $this->groupValue($spec, 'error', $index) ?? UPLOAD_ERR_OK,
                'size' => $this->groupValue($spec, 'size', $index),
            ]);
            if ($file !== null) {
                $group[$index] = $file;
            }
        }

        return $group;
    }

    /**
     * @param array<array-key, mixed> $spec
     */
    private function groupValue(array $spec, string $key, int|string $index): mixed
    {
        $values = $spec[$key] ?? null;

        return is_array($values) ? ($values[$index] ?? null) : null;
    }
}
