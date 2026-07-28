<?php

declare(strict_types=1);

namespace Quiote\Runtime\Superglobals;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Populates PHP's request superglobals from a PSR-7 request, for runtimes that
 * don't do it themselves (RoadRunner, Swoole), and empties them again at the
 * request boundary.
 *
 * Quiote's legacy half still reads them in about two dozen places -- Routing
 * needs $_SERVER['SCRIPT_NAME'] to generate URLs, ext/session finds its id via
 * $_COOKIE, ActionExecutor falls back to $_POST, TelemetryMiddleware wants
 * REQUEST_TIME_FLOAT -- so hydrating here is what lets that code run unchanged
 * off-SAPI. SessionMiddleware already mirrors PSR-7 cookies into $_COOKIE for
 * the same reason; this generalises it.
 *
 * One thing hydration cannot fake: $_FILES entries have no usable tmp_name,
 * because a PSR-7 UploadedFileInterface may be backed by a stream with no file
 * behind it. App code that reads $_FILES directly is unsupported off-SAPI and
 * must use $request->getUploadedFiles() instead. Core reads only $_POST.
 */
final class SuperglobalBridge
{
    /**
     * The process's own $_SERVER (argv, PATH, PWD, ...) as it was before any
     * request touched it, so dehydrate() can restore it rather than leaving a
     * previous request's HTTP_* keys visible to the next one.
     *
     * @var array<string, mixed>
     */
    private array $baselineServer;

    public function __construct()
    {
        /** @var array<string, mixed> $server */
        $server = $_SERVER;
        $this->baselineServer = $server;
    }

    public function hydrate(ServerRequestInterface $request): void
    {
        $_SERVER = array_merge($this->baselineServer, $request->getServerParams());

        $_GET = $request->getQueryParams();
        $parsedBody = $request->getParsedBody();
        $_POST = is_array($parsedBody) ? $parsedBody : [];
        $_COOKIE = $request->getCookieParams();
        // Mirrors PHP's own variables_order default of "GPCS" minus the parts
        // that no longer exist: later keys win, so POST overrides GET.
        $_REQUEST = array_merge($_GET, $_POST);
        $_FILES = self::toFilesArray($request->getUploadedFiles());
    }

    public function dehydrate(): void
    {
        $_SERVER = $this->baselineServer;
        $_GET = $_POST = $_COOKIE = $_REQUEST = $_FILES = [];
    }

    /**
     * @param array<array-key, mixed> $uploadedFiles
     * @return array<string, mixed>
     */
    private static function toFilesArray(array $uploadedFiles): array
    {
        $files = [];
        foreach ($uploadedFiles as $name => $file) {
            $entry = self::toFilesEntry($file);
            if ($entry !== null) {
                $files[(string) $name] = $entry;
            }
        }
        return $files;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function toFilesEntry(mixed $file): ?array
    {
        if ($file instanceof UploadedFileInterface) {
            return [
                'name' => $file->getClientFilename() ?? '',
                'type' => $file->getClientMediaType() ?? '',
                'size' => $file->getSize() ?? 0,
                'error' => $file->getError(),
                // Deliberately empty: see the class docblock.
                'tmp_name' => '',
            ];
        }

        if (is_array($file)) {
            // A nested group (input name="doc[]" / name="doc[a]"). PHP's own
            // $_FILES flips these into parallel arrays keyed by field; that
            // shape is only reconstructible for uniform groups and no core code
            // consumes it, so nested groups are passed through as-is.
            $nested = self::toFilesArray($file);
            return $nested === [] ? null : $nested;
        }

        return null;
    }
}
