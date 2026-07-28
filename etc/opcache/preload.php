<?php

declare(strict_types=1);

/**
 * OPcache preload script for a persistent worker deployment.
 *
 * Worker mode already keeps the app bootstrapped in memory between requests
 * (see Quiote\Runtime\Worker\WorkerRuntimeInterface and its implementations),
 * but each new worker
 * process still pays autoloading + reflection-based autowiring for the
 * Quiote\* core classes on its first request. Preloading compiles those
 * classes into the shared OPcache SHM arena once, at PHP process startup,
 * so every worker starts warm.
 *
 * Deliberately scoped to Quiote/ only: the renderer/db-adapter/auth/telemetry
 * plugin packages under packages/* are optional (require-dev only, see
 * composer.json "suggest") and may not be installed in production, or may
 * depend on PHP extensions (e.g. ext-xsl) that aren't guaranteed present.
 *
 * Enable via php.ini:
 *   opcache.preload=/app/etc/opcache/preload.php
 *   opcache.preload_user=root   ; required whenever the master process starts as root
 *
 * Requires a production (--no-dev) composer install: some require-dev-only
 * packages (e.g. illuminate/reflection, cycle/database, pulled in only by the
 * db-eloquent/db-cycle plugin test suites) emit "Can't preload unlinked
 * class"/"Cannot redeclare class" warnings from their own composer files-
 * autoload entries when present under opcache.preload — unrelated to this
 * script's Quiote/-only loop, and absent once dev dependencies aren't installed.
 */

require __DIR__ . '/../../vendor/autoload.php';

$root = dirname(__DIR__, 2) . '/Quiote';

/** @var iterable<SplFileInfo> $files */
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

foreach ($files as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $path = $file->getPathname();
    $source = file_get_contents($path);

    // Skip procedural bootstrap scripts (e.g. Quiote/version.php, which sets
    // Config values at include time rather than declaring a type) — only
    // files that declare a class/interface/trait/enum are safe to preload.
    if ($source === false || !preg_match('/\b(?:class|interface|trait|enum)\s+\w/', $source)) {
        continue;
    }

    try {
        require_once $path;
    } catch (\Throwable $e) {
        // A class whose parent lives in an optional PHP extension (e.g.
        // QuioteXsltProcessor extends the ext-xsl XSLTProcessor) throws here
        // if that extension isn't installed. Skip it rather than aborting
        // preload for the whole process.
        fwrite(STDERR, sprintf("[opcache-preload] skipped %s: %s\n", $path, $e->getMessage()));
    }
}
