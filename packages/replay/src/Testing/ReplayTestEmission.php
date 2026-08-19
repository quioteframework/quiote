<?php

declare(strict_types=1);

namespace Quiote\Replay\Testing;

use Quiote\Config\Config;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteId;
use Quiote\Replay\Store\FileCassetteStore;
use Quiote\Support\Compiler\FilesystemArtifactWriter;

/**
 * Writes a cassette's own copy plus a generated {@see ReplayTestCase} subclass from it, the
 * "commit this as a regression test" step behind `quiote replay --as-test`. The cassette copy
 * lands at `{replay.tests_path}/cassettes/{slug}.qcast` and the test at
 * `{replay.tests_path}/Replay{slug}Test.php`, both under `core.app_dir`.
 */
final class ReplayTestEmission
{
    /** @return array{test: string, cassette: string} */
    public static function emit(CassetteId $id, Cassette $cassette, bool $expectFixed): array
    {
        $testsDir = rtrim(Config::getString('core.app_dir', ''), '/\\')
            . '/' . trim(Config::getString('replay.tests_path', 'tests/Replay'), '/\\');
        $cassettesDir = $testsDir . '/cassettes';

        (new FileCassetteStore($cassettesDir))->put($id, $cassette);

        $artifact = (new TestEmitter())->emit($cassette, $id, $expectFixed);
        $testPath = $testsDir . '/' . basename($artifact->targetHint);
        (new FilesystemArtifactWriter())->write($artifact, $testPath);

        return ['test' => $testPath, 'cassette' => $cassettesDir . '/' . $id->slug . '.qcast'];
    }
}
