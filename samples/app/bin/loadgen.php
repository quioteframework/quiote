#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Drives real HTTP traffic at a running Quiote sample app -- weighted
 * endpoints, small random pauses between requests, and periodic bursts of
 * concurrent requests -- so `quiote telemetry:dashboard` (or any other
 * OTLP-consuming tool pointed at the app) has something live to show.
 * Deliberately dependency-free (curl only, no Composer autoload): this is a
 * standalone script, not part of the framework or its test suite. See
 * docs/TELEMETRY_DASHBOARD_PLAN.md, Phase 5.
 */

$options = getopt('', [
    'base-url:', 'min-delay-ms:', 'max-delay-ms:', 'burst-every:', 'burst-size:',
    'duration:', 'error-rate:', 'help',
]);

if (isset($options['help'])) {
    fwrite(STDOUT, <<<TXT
    Usage: php loadgen.php [options]

      --base-url=URL       Base URL of the running sample app (default: http://127.0.0.1:8123)
      --min-delay-ms=N     Minimum pause between requests, ms (default: 50)
      --max-delay-ms=N     Maximum pause between requests, ms (default: 400)
      --burst-every=N      Seconds between traffic bursts (default: 15)
      --burst-size=N       Concurrent requests fired per burst (default: 20)
      --duration=N         Seconds to run; 0 = forever (default: 0)
      --error-rate=F       Fraction (0.0-1.0) of requests routed to /boom (default: 0.05)
      --help               Show this message

    TXT);
    exit(0);
}

$baseUrl = rtrim((string) ($options['base-url'] ?? 'http://127.0.0.1:8123'), '/');
$minDelayMs = max(0, (int) ($options['min-delay-ms'] ?? 50));
$maxDelayMs = max($minDelayMs, (int) ($options['max-delay-ms'] ?? 400));
$burstEverySeconds = max(0.5, (float) ($options['burst-every'] ?? 15.0));
$burstSize = max(1, (int) ($options['burst-size'] ?? 20));
$duration = max(0.0, (float) ($options['duration'] ?? 0.0));
$errorRate = max(0.0, min(1.0, (float) ($options['error-rate'] ?? 0.05)));

$baseWeights = ['/' => 0.50, '/about' => 0.25, '/contact' => 0.20];
$remaining = 1.0 - $errorRate;
$baseTotal = array_sum($baseWeights);
$scale = $baseTotal > 0.0 ? $remaining / $baseTotal : 0.0;
$weights = array_map(static fn(float $w): float => $w * $scale, $baseWeights);
$weights['/boom'] = $errorRate;

$stopRequested = false;
if (function_exists('pcntl_async_signals') && defined('SIGINT')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT, function () use (&$stopRequested): void {
        $stopRequested = true;
    });
}

/** @param array<string,float> $weights */
function loadgen_pick_path(array $weights): string
{
    $roll = mt_rand() / mt_getrandmax();
    $cumulative = 0.0;
    foreach ($weights as $path => $weight) {
        $cumulative += $weight;
        if ($roll <= $cumulative) {
            return $path;
        }
    }

    return array_key_first($weights);
}

/** @return array{0:int,1:?string} [http status code, curl error message or null] */
function loadgen_fire(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_NOSIGNAL => true,
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_errno($ch) !== 0 ? curl_error($ch) : null;
    curl_close($ch);

    return [$code, $error];
}

/**
 * @param array<string,float> $weights
 * @return list<array{0:int,1:?string}>
 */
function loadgen_fire_burst(string $baseUrl, array $weights, int $size): array
{
    $multi = curl_multi_init();
    $handles = [];

    for ($i = 0; $i < $size; $i++) {
        $ch = curl_init($baseUrl . loadgen_pick_path($weights));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_NOSIGNAL => true,
        ]);
        curl_multi_add_handle($multi, $ch);
        $handles[] = $ch;
    }

    $running = null;
    do {
        curl_multi_exec($multi, $running);
        if ($running > 0) {
            curl_multi_select($multi, 0.1);
        }
    } while ($running > 0);

    $results = [];
    foreach ($handles as $ch) {
        $results[] = [
            (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
            curl_errno($ch) !== 0 ? curl_error($ch) : null,
        ];
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
    }
    curl_multi_close($multi);

    return $results;
}

$sent = 0;
$ok = 0;
$httpErrors = 0;
$connectErrors = 0;
$startedAt = microtime(true);
$lastBurstAt = $startedAt;
$lastStatusAt = $startedAt;

fwrite(STDERR, sprintf("loadgen: hammering %s (Ctrl+C to stop)\n", $baseUrl));

while (!$stopRequested) {
    $now = microtime(true);

    if ($duration > 0.0 && ($now - $startedAt) >= $duration) {
        break;
    }

    if (($now - $lastBurstAt) >= $burstEverySeconds) {
        $results = loadgen_fire_burst($baseUrl, $weights, $burstSize);
        $lastBurstAt = $now;
    } else {
        $results = [loadgen_fire($baseUrl . loadgen_pick_path($weights))];
    }

    foreach ($results as [$code, $error]) {
        $sent++;
        if ($error !== null) {
            $connectErrors++;
        } elseif ($code >= 200 && $code < 400) {
            $ok++;
        } else {
            $httpErrors++;
        }
    }

    if ((microtime(true) - $lastStatusAt) >= 1.0) {
        $elapsed = microtime(true) - $startedAt;
        fwrite(STDERR, sprintf(
            "\rsent=%d ok=%d err=%d connect_err=%d rps=%.1f",
            $sent,
            $ok,
            $httpErrors,
            $connectErrors,
            $elapsed > 0 ? $sent / $elapsed : 0.0,
        ));
        $lastStatusAt = microtime(true);
    }

    usleep(random_int($minDelayMs, $maxDelayMs) * 1000);
}

fwrite(STDERR, sprintf(
    "\nloadgen: stopped. sent=%d ok=%d err=%d connect_err=%d\n",
    $sent,
    $ok,
    $httpErrors,
    $connectErrors,
));
