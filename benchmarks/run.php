<?php

declare(strict_types=1);

/**
 * Self-contained micro-benchmark harness for the framework's per-request hot
 * paths. No external benchmarking dependency: each benchmark is warmed, then
 * sampled over several rounds, and the minimum ns/op (least noise-prone for
 * micro-benchmarks) plus median are reported.
 *
 * Usage:
 *   php benchmarks/run.php run <label>        # run all benchmarks, save results/<label>.json
 *   php benchmarks/run.php compare <a> <b>    # print delta between results/<a>.json and <b>.json
 *
 * Default (no args): run baseline.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Quiote\Config\Config;

// ---------------------------------------------------------------------------
// Minimal framework bootstrap (mirrors tests/bootstrap.php essentials so Config
// lookups and the category logger resolve without a full Quiote::bootstrap()).
// ---------------------------------------------------------------------------
$srcDir = realpath(__DIR__ . '/../Quiote');
$appDir = realpath(__DIR__ . '/../tests/sandbox/app') ?: __DIR__;
Config::set('core.app_dir', $appDir);
Config::set('core.config_dir', $appDir . '/Config/');
Config::set('core.cache_dir', sys_get_temp_dir());
Config::set('core.system_config_dir', $srcDir . '/Config/defaults/');
Config::set('core.environment', 'prod');
Config::set('core.default_context', 'web');
Config::set('core.use_translation', true, true);
// Sets core.quiote_dir (and other core constants) needed by the translation stack.
require_once __DIR__ . '/../Quiote/Quiote.php';

/**
 * Lazily build (once) a started TranslationManager for the formatter benchmarks.
 */
function translationManager(): \Quiote\Translation\TranslationManager
{
    static $tm = null;
    if ($tm !== null) {
        return $tm;
    }
    $ctx = \Quiote\Context::getInstance();
    $existing = $ctx->getTranslationManager();
    if ($existing instanceof \Quiote\Translation\TranslationManager) {
        return $tm = $existing;
    }
    $ctx->setFactoryInfo('translation_manager', [
        'class' => \Quiote\Translation\TranslationManager::class,
        'parameters' => [],
    ]);
    $built = $ctx->createInstanceFor('translation_manager');
    if (!$built instanceof \Quiote\Translation\TranslationManager) {
        throw new RuntimeException('Could not build a TranslationManager for the benchmark.');
    }
    $built->startup();
    return $tm = $built;
}

// ---------------------------------------------------------------------------
// Benchmark harness
// ---------------------------------------------------------------------------

/**
 * @param callable():mixed $fn
 * @return array{min: float, median: float, mean: float, iters: int, rounds: int}
 */
function bench(callable $fn, int $iterations, int $rounds = 15, int $warmup = 500): array
{
    for ($w = 0; $w < $warmup; $w++) {
        $fn();
    }
    $samples = [];
    for ($r = 0; $r < $rounds; $r++) {
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $fn();
        }
        $elapsed = hrtime(true) - $start;
        $samples[] = $elapsed / $iterations; // ns per op
    }
    sort($samples);
    $count = count($samples);
    $median = $count % 2 === 0
        ? ($samples[intdiv($count, 2) - 1] + $samples[intdiv($count, 2)]) / 2
        : $samples[intdiv($count, 2)];
    return [
        'min' => $samples[0],
        'median' => $median,
        'mean' => array_sum($samples) / $count,
        'iters' => $iterations,
        'rounds' => $rounds,
    ];
}

// ---------------------------------------------------------------------------
// Fixtures / stubs (global namespace; not PSR-4 autoloadable by design)
// ---------------------------------------------------------------------------

/** Exposes the protected static resolver so it can be benchmarked in isolation. */
final class BenchConfigCache extends \Quiote\Config\ConfigCache
{
    public static function resolve(string $filename): string
    {
        return self::resolveConfigFormat($filename);
    }
}

/** Cheap layer: skips renderer/slots so renderLayers' own attribute-copy loop dominates. */
class BenchLayer extends \Quiote\View\TemplateLayer
{
    public function execute(?\Quiote\Renderer\Renderer $renderer = null, array &$attributes = [], array &$moreAssigns = []): string
    {
        return 'L' . (string) ($attributes['inner'] ?? '');
    }

    public function getResourceStreamIdentifier(): ?string
    {
        return null;
    }
}

class BenchView extends \Quiote\View\View
{
    public function execute(\Quiote\Request\WebRequest $rd): void {}
}

/**
 * Write a minimal valid little-endian .mo file with the given msgid => msgstr map.
 * @param array<string, string> $pairs
 */
function writeMoFile(string $path, array $pairs): void
{
    $ids = array_keys($pairs);
    sort($ids, SORT_STRING);
    $n = count($ids);

    $headerSize = 28;                 // 4-byte magic + 6 * 4-byte header longs
    $tablesSize = $n * 8 * 2;         // two (length, offset) uint32 tables
    $offset = $headerSize + $tablesSize;

    $origData = '';
    $origTable = '';
    foreach ($ids as $id) {
        $len = strlen($id);
        $origTable .= pack('VV', $len, $offset);
        $origData .= $id . "\0";
        $offset += $len + 1;
    }
    $transData = '';
    $transTable = '';
    foreach ($ids as $id) {
        $str = $pairs[$id];
        $len = strlen($str);
        $transTable .= pack('VV', $len, $offset);
        $transData .= $str . "\0";
        $offset += $len + 1;
    }

    $mo = pack('V', 0x950412de)       // magic (little endian)
        . pack('V', 0)                // revision
        . pack('V', $n)               // number of strings
        . pack('V', $headerSize)      // original string table offset
        . pack('V', $headerSize + $n * 8) // translated string table offset
        . pack('V', 0)                // hash table size
        . pack('V', 0)                // hash table offset
        . $origTable . $transTable . $origData . $transData;

    file_put_contents($path, $mo);
}

// ---------------------------------------------------------------------------
// Benchmark definitions
// ---------------------------------------------------------------------------

/** @return array<string, callable():array<string,mixed>> */
function benchmarks(): array
{
    return [
        // Tier 1 #1: ConfigCache::resolveConfigFormat filesystem-format resolution.
        'config_resolve_format' => static function (): array {
            $file = realpath(__DIR__ . '/../Quiote/Config/defaults/config_handlers.xml');
            if ($file === false) {
                throw new RuntimeException('benchmark config file missing');
            }
            return bench(static fn() => BenchConfigCache::resolve($file), 5000, 15, 2000);
        },

        // Tier 1 #3: WebResponse construction (status-code table materialization).
        'webresponse_construct' => static function (): array {
            return bench(static fn() => new \Quiote\Response\WebResponse(), 5000, 15, 2000);
        },

        // Tier 1 #2: View::renderLayers attribute-map rebuild per layer.
        'view_render_layers' => static function (): array {
            $view = new BenchView();
            $attrs = [];
            for ($i = 0; $i < 25; $i++) {
                $attrs['attr_' . $i] = 'value_' . $i;
            }
            $view->setAttributes($attrs);
            for ($l = 0; $l < 4; $l++) {
                $view->appendLayer(new BenchLayer(['name' => 'layer_' . $l]));
            }
            return bench(static fn() => $view->renderLayers(), 2000, 15, 500);
        },

        // Tier 1 #4: gettext .mo catalog read + unpack.
        'gettext_read_mo' => static function (): array {
            $path = sys_get_temp_dir() . '/quiote_bench_catalog.mo';
            $pairs = ['' => "Project-Id-Version: bench\nPlural-Forms: nplurals=2; plural=(n != 1);\n"];
            for ($i = 0; $i < 500; $i++) {
                $pairs['source_string_number_' . $i] = 'translated_string_number_' . $i;
            }
            writeMoFile($path, $pairs);
            return bench(static fn() => \Quiote\Translation\Gettext\GettextMoReader::readFile($path), 1000, 15, 200);
        },

        // Tier 2 #5: ICU currency formatting (NumberFormatter + ResourceBundle per call).
        'currency_format' => static function (): array {
            $tm = translationManager();
            $loc = $tm->getLocale('en_US');
            return bench(static fn() => $tm->_c(1234.56, null, $loc), 2000, 15, 500);
        },

        // Tier 2 #5: ICU decimal formatting (NumberFormatter per call).
        'number_format' => static function (): array {
            $tm = translationManager();
            $loc = $tm->getLocale('en_US');
            return bench(static fn() => $tm->_n(1234567.89, null, $loc), 2000, 15, 500);
        },

        // Tier 2 #5: ICU date formatting (IntlDateFormatter per call).
        'date_format' => static function (): array {
            $tm = translationManager();
            $loc = $tm->getLocale('en_US');
            $dt = new \DateTimeImmutable('2024-03-15 12:00:00');
            return bench(static fn() => $tm->_d($dt, null, $loc), 2000, 15, 500);
        },

        // Tier 2 #6: applying route/query/body params onto the immutable WebRequest.
        // The per-key setParameter() loop is what ActionExecutor/Kernel do today;
        // the bulk withParameters() path is measured too when available.
        'webrequest_params_loop' => static function (): array {
            $params = [];
            for ($i = 0; $i < 20; $i++) {
                $params['param_' . $i] = 'value_' . $i;
            }
            return bench(static function () use ($params): void {
                $req = new \Quiote\Request\WebRequest();
                foreach ($params as $k => $v) {
                    $req = $req->setParameter($k, $v);
                }
            }, 3000, 15, 500);
        },

        'webrequest_params_bulk' => static function (): ?array {
            if (!method_exists(\Quiote\Request\WebRequest::class, 'withParameters')) {
                return null; // not implemented yet (baseline run)
            }
            $params = [];
            for ($i = 0; $i < 20; $i++) {
                $params['param_' . $i] = 'value_' . $i;
            }
            return bench(static function () use ($params): void {
                $req = new \Quiote\Request\WebRequest();
                $req->withParameters($params);
            }, 3000, 15, 500);
        },
    ];
}

// ---------------------------------------------------------------------------
// Commands
// ---------------------------------------------------------------------------

function resultsDir(): string
{
    $dir = __DIR__ . '/results';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    return $dir;
}

function runAll(string $label): void
{
    $results = [];
    printf("Running benchmarks (label: %s), PHP %s\n\n", $label, PHP_VERSION);
    printf("%-28s %14s %14s %14s\n", 'benchmark', 'min ns/op', 'median ns/op', 'mean ns/op');
    printf("%s\n", str_repeat('-', 74));
    foreach (benchmarks() as $name => $fn) {
        $r = $fn();
        if ($r === null) {
            continue; // benchmark not applicable in this run (e.g. API not yet implemented)
        }
        $results[$name] = $r;
        printf("%-28s %14.1f %14.1f %14.1f\n", $name, $r['min'], $r['median'], $r['mean']);
    }
    $path = resultsDir() . '/' . $label . '.json';
    file_put_contents($path, json_encode($results, JSON_PRETTY_PRINT) . "\n");
    printf("\nSaved: %s\n", $path);
}

function compare(string $a, string $b): void
{
    $pathA = resultsDir() . '/' . $a . '.json';
    $pathB = resultsDir() . '/' . $b . '.json';
    $da = json_decode((string) file_get_contents($pathA), true);
    $db = json_decode((string) file_get_contents($pathB), true);
    if (!is_array($da) || !is_array($db)) {
        fwrite(STDERR, "Cannot read result files.\n");
        exit(1);
    }
    printf("Comparing %s -> %s (min ns/op)\n\n", $a, $b);
    printf("%-28s %14s %14s %10s\n", 'benchmark', $a, $b, 'change');
    printf("%s\n", str_repeat('-', 70));
    foreach ($da as $name => $ra) {
        if (!isset($db[$name])) {
            continue;
        }
        $va = (float) $ra['min'];
        $vb = (float) $db[$name]['min'];
        $pct = $va > 0.0 ? (($vb - $va) / $va) * 100.0 : 0.0;
        printf("%-28s %14.1f %14.1f %9.1f%%\n", $name, $va, $vb, $pct);
    }
}

$cmd = $argv[1] ?? 'run';
switch ($cmd) {
    case 'compare':
        compare($argv[2] ?? 'baseline', $argv[3] ?? 'tier1');
        break;
    case 'run':
    default:
        runAll($argv[2] ?? 'baseline');
        break;
}
