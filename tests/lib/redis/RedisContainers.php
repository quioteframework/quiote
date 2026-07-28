<?php

namespace Quiote\Test\Redis;

use Predis\Client;
use Testcontainers\Container\StartedTestContainer;
use Testcontainers\Modules\RedisContainer;

/**
 * Lazily starts (and, at process shutdown, stops) a single shared Redis
 * container for the integration test suite, mirroring
 * {@see \Quiote\Test\Database\DatabaseContainers}.
 */
final class RedisContainers
{
    private const LABEL = 'quiote.itest';

    private static ?StartedTestContainer $container = null;

    private static bool $shutdownRegistered = false;

    private static bool $orphansPruned = false;

    public static function dockerAvailable(): bool
    {
        static $available = null;
        if ($available !== null) {
            return $available;
        }
        if (!class_exists(RedisContainer::class)) {
            return $available = false;
        }
        $rc = 1;
        $out = [];
        @exec('docker info > /dev/null 2>&1', $out, $rc);
        return $available = ($rc === 0);
    }

    public static function dsn(): string
    {
        $container = self::$container;
        if ($container === null) {
            self::pruneOrphans();
            self::ensureImage('redis:7');
            $container = (new RedisContainer('7'))
                ->withLabels([self::LABEL => '1'])
                ->withAutoRemove(true)
                ->start();
            self::$container = $container;
            self::registerShutdown();
        }

        $dsn = sprintf('redis://%s:%d', $container->getHost(), $container->getFirstMappedPort());
        self::awaitReady($dsn);

        return $dsn;
    }

    private static function awaitReady(string $dsn, int $timeoutSeconds = 60): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $lastError = null;
        do {
            try {
                (new Client($dsn))->connect();
                return;
            } catch (\Throwable $e) {
                $lastError = $e;
                usleep(500_000);
            }
        } while (microtime(true) < $deadline);

        throw new \RuntimeException(
            'Redis container did not become connectable within ' . $timeoutSeconds
            . 's: ' . $lastError->getMessage(),
        );
    }

    private static function pruneOrphans(): void
    {
        if (self::$orphansPruned) {
            return;
        }
        self::$orphansPruned = true;
        @exec(
            'docker ps -aq --filter label=' . self::LABEL . ' 2>/dev/null | xargs -r docker rm -f > /dev/null 2>&1'
        );
    }

    private static function ensureImage(string $image): void
    {
        $rc = 1;
        $out = [];
        @exec('docker image inspect ' . escapeshellarg($image) . ' > /dev/null 2>&1', $out, $rc);
        if ($rc !== 0) {
            @exec('docker pull ' . escapeshellarg($image) . ' > /dev/null 2>&1', $out, $rc);
        }
    }

    private static function registerShutdown(): void
    {
        if (self::$shutdownRegistered) {
            return;
        }
        self::$shutdownRegistered = true;
        register_shutdown_function(static function (): void {
            if (self::$container instanceof StartedTestContainer) {
                try {
                    self::$container->stop();
                } catch (\Throwable) {
                    // best-effort teardown
                }
            }
        });
    }
}
