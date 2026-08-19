<?php

declare(strict_types=1);

namespace Quiote\Replay\Console;

use Quiote\Config\Config;
use Quiote\Console\Command\AbstractAppCommand;
use Quiote\Context;
use Quiote\Replay\Cassette\CassetteId;
use Quiote\Replay\Index\IndexHints;
use Quiote\Replay\Replay\ReplayEngine;
use Quiote\Replay\Replay\ReplayException;
use Quiote\Replay\Testing\ReplayTestEmission;
use Quiote\Support\Compiler\Diagnostic;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * `quiote replay <id>` -- re-runs a recorded cassette against the real pipeline and reports drift,
 * and, with `--as-test`, emits a committed regression test from it. The cassette is resolved via
 * {@see ResolvesCassetteViaIndexes}: the local cache, then whichever store `replay.store` names,
 * then -- given `--key`/`--date`/`--hour`, or none at all if a `log-analytics` index is configured
 * -- the cassette-index chain. `--save` fetches and caches the cassette without replaying it, the
 * same operation `quiote cassette:fetch` performs under its own name. `--live` is not offered --
 * see {@see ReplayEngine}'s docblock for why there is currently only one mode to run in.
 */
#[AsCommand(name: 'replay', description: 'Re-run a recorded cassette against the live app and report drift')]
final class ReplayCommand extends AbstractAppCommand
{
    use ResolvesCassetteViaIndexes;

    protected function configure(): void
    {
        $this->configureAppOptions();
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'The cassette id')
            ->addOption('context', null, InputOption::VALUE_REQUIRED, 'Context to replay against (defaults to the cassette\'s own recorded context, else core.default_context)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Allow replaying a non-idempotent (e.g. POST) request')
            ->addOption('save', null, InputOption::VALUE_NONE, 'Fetch and cache the cassette locally without replaying it')
            ->addOption('key', null, InputOption::VALUE_REQUIRED, 'An exact store key pasted from a pointer log line, bypassing id-based resolution')
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'A YYYY-MM-DD hint narrowing a prefix scan to one day')
            ->addOption('hour', null, InputOption::VALUE_REQUIRED, 'An 00-23 hint narrowing a prefix scan to one hour of --date')
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

        $id = CassetteId::fromRaw($idArgument);
        $hints = new IndexHints(
            key: self::stringOption($input, 'key'),
            date: self::stringOption($input, 'date'),
            hour: self::stringOption($input, 'hour'),
        );

        $fetched = $this->fetchCassette($io, $id, $hints);
        if ($fetched === null) {
            return self::FAILURE;
        }
        $cassette = $fetched['cassette'];

        if ($input->getOption('save')) {
            if ($input->getOption('json')) {
                $output->writeln(json_encode([
                    'id' => $idArgument,
                    'source' => $fetched['source'],
                    'cached_path' => $fetched['cached_path'],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

                return self::SUCCESS;
            }
            $io->success(sprintf('Fetched cassette "%s" from %s.', $idArgument, $fetched['source']));
            if ($fetched['cached_path'] !== null) {
                $io->writeln(sprintf('Cached at: %s', $fetched['cached_path']));
            }

            return self::SUCCESS;
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
                $emitted = ReplayTestEmission::emit($id, $cassette, (bool)$input->getOption('expect-fixed'));
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
            $recordedMethod = $cassette->request['method'] ?? null;
            if (is_string($recordedMethod) && !ReplayEngine::isSafeMethod($recordedMethod)) {
                // Said at emit time, not left for CI to discover: the generated test dispatches a
                // write on every run, and ReplayTestCase refuses that until a suite opts in.
                $io->warning(sprintf(
                    'The emitted test replays a %s request, which re-performs its writes on every run. '
                    . 'It will refuse to run until replay.tests_allow_live=true is set for the suite, and '
                    . 'that is only safe where the test environment is disposable.',
                    strtoupper($recordedMethod),
                ));
            }
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
}
