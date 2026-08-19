<?php

declare(strict_types=1);

namespace Quiote\Replay\Console;

use Quiote\Console\Command\AbstractAppCommand;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteCodec;
use Quiote\Replay\Cassette\CassetteId;
use Quiote\Replay\Cassette\Effect;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * `cassette:show <id>` -- decodes one cassette and prints the requested
 * projection. Request/response bodies are excerpted (length + sha256 by
 * default) unless `--include-bodies` is passed, so a 2 MiB cassette does not
 * become 2 MiB of terminal output by accident.
 */
#[AsCommand(name: 'cassette:show', description: 'Show one cassette from the configured replay store')]
final class CassetteShowCommand extends AbstractAppCommand
{
    use ResolvesCassetteStore;

    protected function configure(): void
    {
        $this->configureAppOptions();
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'The cassette id (or, with --raw, a path to a plain-JSON cassette file)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the whole projection as one JSON document')
            ->addOption('raw', null, InputOption::VALUE_NONE, 'Treat <id> as a path to an already-uncompressed (plain JSON) cassette file, bypassing the configured store')
            ->addOption('section', null, InputOption::VALUE_REQUIRED, 'Only show one top-level section (meta, request, resolved, session, user, effects, response, exception, log)')
            ->addOption('include-bodies', null, InputOption::VALUE_NONE, 'Include full request/response body content (excerpted to length + sha256 by default)');
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

        $cassette = (bool)$input->getOption('raw')
            ? $this->loadRaw($idArgument, $io)
            : $this->loadFromStore($idArgument, $io);
        if ($cassette === null) {
            return self::FAILURE;
        }

        $projection = $this->project($cassette, (bool)$input->getOption('include-bodies'));

        $sectionOption = $input->getOption('section');
        if (is_string($sectionOption) && $sectionOption !== '') {
            if (!array_key_exists($sectionOption, $projection)) {
                $io->error(sprintf('Unknown --section "%s"; expected one of: %s.', $sectionOption, implode(', ', array_keys($projection))));

                return self::FAILURE;
            }
            $projection = [$sectionOption => $projection[$sectionOption]];
        }

        if ($input->getOption('json')) {
            $output->writeln(json_encode($projection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

            return self::SUCCESS;
        }

        foreach ($projection as $key => $value) {
            $io->section((string)$key);
            $output->writeln(json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: 'null');
        }

        return self::SUCCESS;
    }

    private function loadRaw(string $path, SymfonyStyle $io): ?Cassette
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            $io->error(sprintf('Could not read "%s".', $path));

            return null;
        }
        try {
            return (new CassetteCodec())->decodeRaw($contents);
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return null;
        }
    }

    private function loadFromStore(string $id, SymfonyStyle $io): ?Cassette
    {
        $store = $this->resolveCassetteStore($io);
        if ($store === null) {
            return null;
        }

        try {
            $cassette = $store->get(CassetteId::fromRaw($id));
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return null;
        }

        if ($cassette === null) {
            $io->error(sprintf('No cassette found for id "%s".', $id));

            return null;
        }

        return $cassette;
    }

    /** @return array<string, mixed> */
    private function project(Cassette $cassette, bool $includeBodies): array
    {
        return [
            'meta' => $cassette->meta,
            'request' => self::projectSection($cassette->request, $includeBodies),
            'resolved' => $cassette->resolved,
            'session' => $cassette->session,
            'user' => $cassette->user,
            'effects' => array_map(static fn(Effect $effect): array => self::effectToArray($effect, $includeBodies), $cassette->effects),
            'response' => self::projectSection($cassette->response, $includeBodies),
            'exception' => $cassette->exception,
            'log' => $cassette->log,
        ];
    }

    /**
     * @param array<string, mixed> $section
     * @return array<string, mixed>
     */
    private static function projectSection(array $section, bool $includeBodies): array
    {
        if ($includeBodies) {
            return $section;
        }

        $body = $section['body'] ?? null;
        if (!is_array($body) || !isset($body['content']) || !is_string($body['content'])) {
            return $section;
        }

        $content = $body['content'];
        $section['body'] = [
            'encoding' => $body['encoding'] ?? null,
            'length' => strlen($content),
            'sha256' => hash('sha256', $content),
            'truncated' => $body['truncated'] ?? false,
        ];

        return $section;
    }

    /**
     * `call` (sql/source/bound_params, for a DB effect) is always shown in full: it's small and
     * already redacted at capture time by whichever driver-specific recorder produced it, unlike
     * a request/response body. `result`'s captured rows are the one part of an effect that can
     * genuinely be large -- a per-query cap, independent of `replay.max_effects` -- so those
     * follow the same excerpt-by-default rule {@see projectSection()} applies to bodies.
     *
     * @return array<string, mixed>
     */
    private static function effectToArray(Effect $effect, bool $includeBodies): array
    {
        $result = $effect->result;
        if (!$includeBodies && is_array($result) && is_array($result['rows'] ?? null)) {
            $result['rows'] = ['excerpted' => true, 'captured_row_count' => count($result['rows'])];
        }

        return [
            'seq' => $effect->seq,
            'kind' => $effect->kind->value,
            'fingerprint' => $effect->fingerprint,
            'call' => $effect->call,
            'result' => $result,
            'duration_us' => $effect->durationMicros,
        ];
    }
}
