<?php

declare(strict_types=1);

namespace Quiote\Replay\Console;

use Quiote\Config\Config;
use Quiote\Console\Command\AbstractAppCommand;
use Quiote\Context;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteId;
use Quiote\Replay\Replay\ReplayEngine;
use Quiote\Replay\Replay\ReplayException;
use Quiote\Replay\Store\FileCassetteStore;
use Quiote\Replay\Testing\TestEmitter;
use Quiote\Support\Compiler\Diagnostic;
use Quiote\Support\Compiler\FilesystemArtifactWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * `quiote replay <id>` -- re-runs a recorded cassette against the real
 * pipeline and reports drift (§7.2), and, with `--as-test`, emits a
 * committed regression test from it (§8). `--save`/`--live` from §9's full
 * signature are not offered: `--save` belongs to the fetch-from-a-remote-store
 * flow (§12.4, no non-file store exists yet), and `--live` is not offered
 * either -- see {@see ReplayEngine}'s docblock for why there is currently
 * only one mode to run in.
 */
#[AsCommand(name: 'replay', description: 'Re-run a recorded cassette against the live app and report drift')]
final class ReplayCommand extends AbstractAppCommand
{
    protected function configure(): void
    {
        $this->configureAppOptions();
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'The cassette id')
            ->addOption('context', null, InputOption::VALUE_REQUIRED, 'Context to replay against (defaults to the cassette\'s own recorded context, else core.default_context)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Allow replaying a non-idempotent (e.g. POST) request')
            ->addOption('as-test', null, InputOption::VALUE_NONE, 'Emit a committed regression test (replay.tests_path, default tests/Replay/) alongside a copy of the cassette')
            ->addOption('expect-fixed', null, InputOption::VALUE_NONE, 'With --as-test, emit an inverted skeleton (markTestIncomplete) for the intended, fixed behaviour instead of asserting the recorded response')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->bootstrapApp($input);
        $io = new SymfonyStyle($input, $output);

        $idArgument = $input->getArgument('id');
        if (!is_string($idArgument) || $idArgument === '') {
            $io->error('A cassette id is required.');

            return self::FAILURE;
        }

        $storeAlias = Config::getString('replay.store', 'file');
        if ($storeAlias !== 'file') {
            $io->error(sprintf('replay only supports the "file" store today; "replay.store" is "%s".', $storeAlias));

            return self::FAILURE;
        }

        $id = CassetteId::fromRaw($idArgument);
        try {
            $store = new FileCassetteStore(Config::getString('replay.store.path', 'var/cassettes'));
            $cassette = $store->get($id);
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }
        if ($cassette === null) {
            $io->error(sprintf('No cassette found for id "%s".', $idArgument));

            return self::FAILURE;
        }

        $contextOption = $input->getOption('context');
        $recordedContext = $cassette->meta['context'] ?? null;
        $contextName = match (true) {
            is_string($contextOption) && $contextOption !== '' => $contextOption,
            is_string($recordedContext) && $recordedContext !== '' => $recordedContext,
            default => Config::getString('core.default_context', 'web'),
        };

        try {
            $context = Context::getInstance($contextName);
            $result = (new ReplayEngine())->replay($context, $cassette, (bool)$input->getOption('force'));
        } catch (ReplayException $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $io->error(sprintf('Could not resolve context "%s": %s', $contextName, $e->getMessage()));

            return self::FAILURE;
        }

        $recordedStatus = $cassette->response['status'] ?? null;
        $diagnostics = $result->drift->diagnostics;

        $emitted = null;
        if ($input->getOption('as-test')) {
            try {
                $emitted = $this->emitTest($id, $cassette, (bool)$input->getOption('expect-fixed'));
            } catch (Throwable $e) {
                $io->error(sprintf('Could not emit test: %s', $e->getMessage()));

                return self::FAILURE;
            }
        }

        if ($input->getOption('json')) {
            $payload = [
                'replayed_status' => $result->response->getStatusCode(),
                'recorded_status' => $recordedStatus,
                'clean' => $result->drift->isClean(),
                'diagnostics' => array_map(static fn(Diagnostic $d): array => $d->toArray(), $diagnostics),
            ];
            if ($emitted !== null) {
                $payload['emitted'] = $emitted;
            }
            $output->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

            return $result->drift->hasErrors() ? self::FAILURE : self::SUCCESS;
        }

        $io->writeln(sprintf(
            'Replayed status: %d (recorded: %s)',
            $result->response->getStatusCode(),
            is_int($recordedStatus) ? (string)$recordedStatus : '?',
        ));

        if ($emitted !== null) {
            $io->writeln(sprintf('Emitted test: %s', $emitted['test']));
            $io->writeln(sprintf('Emitted cassette: %s', $emitted['cassette']));
        }

        if ($result->drift->isClean()) {
            $io->success('No drift detected.');

            return self::SUCCESS;
        }

        foreach ($diagnostics as $diagnostic) {
            $message = sprintf('[%s] %s', $diagnostic->code, $diagnostic->message);
            if ($diagnostic->severity === Diagnostic::SEVERITY_ERROR) {
                $io->error($message);
            } else {
                $io->warning($message);
            }
        }

        return $result->drift->hasErrors() ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Writes the cassette to `{replay.tests_path}/cassettes/{slug}.qcast` and the generated test
     * to `{replay.tests_path}/Replay{slug}Test.php`, both under `core.app_dir` -- §17 item 5's own
     * open question ("tests/Replay/ in the app, or a configurable path") resolved here as
     * configurable, defaulting to the path §8's own example uses.
     *
     * @return array{test: string, cassette: string}
     */
    private function emitTest(CassetteId $id, Cassette $cassette, bool $expectFixed): array
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
