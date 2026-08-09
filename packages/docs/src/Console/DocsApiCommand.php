<?php

declare(strict_types=1);

namespace Quiote\Docs\Console;

use Quiote\Docs\ApiReflector;
use Quiote\Docs\DocsGenerator;
use Quiote\Docs\Scan\SourceScanner;
use Quiote\Support\Compiler\ArtifactDriftChecker;
use Quiote\Support\Compiler\Diagnostic;
use Quiote\Support\Compiler\EmittedArtifact;
use Quiote\Support\Compiler\FilesystemArtifactWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Writes the API reference into the documentation site.
 *
 * The site is built in a repository that has no PHP, so the pages have to be generated
 * here and committed there. `--check` is the other half of that arrangement: it compares
 * what the current source would produce against what is committed, without writing, so a
 * public API change that nobody regenerated fails a build rather than going unnoticed.
 */
#[AsCommand(
    name: 'docs:api',
    description: "Generate the framework's API reference as Markdown pages for the documentation site",
)]
final class DocsApiCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                "Directory to write the reference into, usually the docs site's src/content/docs/api",
            )
            ->addOption(
                'check',
                null,
                InputOption::VALUE_NONE,
                'Compare against what is already there and report differences without writing',
            )
            ->addOption(
                'base-path',
                null,
                InputOption::VALUE_REQUIRED,
                'URL path the reference is served under, used for links between pages',
                '/api',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $outputDir = $input->getOption('output');
        if (!is_string($outputDir) || $outputDir === '') {
            $io->error('Pass --output with the directory to write the reference into.');

            return self::INVALID;
        }

        $basePath = $input->getOption('base-path');
        $basePath = is_string($basePath) && $basePath !== '' ? rtrim($basePath, '/') : '/api';

        $scanner = new SourceScanner();
        $reflector = new ApiReflector();
        $generator = new DocsGenerator(basePath: $basePath);

        $scanned = $scanner->scan();
        $index = $reflector->build($scanned);
        $artifacts = $generator->generate($index);

        $diagnostics = [
            ...$scanner->getDiagnostics(),
            ...$reflector->getDiagnostics(),
            ...$generator->getDiagnostics(),
        ];

        $io->text(sprintf(
            'Read %d types in %d namespaces; %d pages to write.',
            count($index->classes()),
            count($index->namespaces()),
            count($artifacts) - 1,
        ));

        $this->renderDiagnostics($io, $diagnostics);

        if ($this->hasErrors($diagnostics)) {
            $io->error('Generation stopped: the problems above would produce a site that cannot build.');

            return self::FAILURE;
        }

        return $input->getOption('check') === true
            ? $this->check($io, $artifacts, $generator, $outputDir)
            : $this->write($io, $artifacts, $generator, $outputDir);
    }

    /**
     * @param array<string, EmittedArtifact> $artifacts
     */
    private function write(
        SymfonyStyle $io,
        array $artifacts,
        DocsGenerator $generator,
        string $outputDir,
    ): int {
        $previous = $generator->readManifest($outputDir) ?? [];
        $writer = new FilesystemArtifactWriter();
        $checker = new ArtifactDriftChecker();

        $written = 0;
        $unchanged = 0;

        foreach ($artifacts as $target => $artifact) {
            $path = rtrim($outputDir, '/') . '/' . $target;

            // Rewriting an identical page would still update its timestamp, which makes the
            // site's dev server reload every page on every run.
            if ($checker->check($artifact, $path)->matches) {
                $unchanged++;
                continue;
            }

            $writer->write($artifact, $path);
            $written++;
        }

        $removed = $this->prune($artifacts, $previous, $outputDir);

        $io->success(sprintf(
            '%d written, %d unchanged, %d removed in %s',
            $written,
            $unchanged,
            $removed,
            $outputDir,
        ));

        return self::SUCCESS;
    }

    /**
     * @param array<string, EmittedArtifact> $artifacts
     */
    private function check(
        SymfonyStyle $io,
        array $artifacts,
        DocsGenerator $generator,
        string $outputDir,
    ): int {
        $checker = new ArtifactDriftChecker();
        $changed = [];
        $missing = [];

        foreach ($artifacts as $target => $artifact) {
            $result = $checker->check($artifact, rtrim($outputDir, '/') . '/' . $target);
            if ($result->matches) {
                continue;
            }

            if ($result->existingChecksum === null) {
                $missing[] = $target;
            } else {
                $changed[] = $target;
            }
        }

        $stale = array_values(array_diff(
            array_keys($generator->readManifest($outputDir) ?? []),
            array_keys($artifacts),
        ));

        if ($missing === [] && $changed === [] && $stale === []) {
            $io->success('The committed reference matches the source.');

            return self::SUCCESS;
        }

        $this->listPaths($io, 'Missing', $missing);
        $this->listPaths($io, 'Out of date', $changed);
        $this->listPaths($io, 'No longer generated', $stale);

        $io->error('The reference is out of date. Run `quiote docs:api --output=…` and commit the result.');

        return self::FAILURE;
    }

    /**
     * Deletes pages this run did not produce but a previous one did.
     *
     * Only files the last manifest claims are removed, so anything hand-written that happens
     * to live in the same directory is left alone.
     *
     * @param array<string, EmittedArtifact> $artifacts
     * @param array<string, string> $previous
     */
    private function prune(array $artifacts, array $previous, string $outputDir): int
    {
        $removed = 0;

        foreach (array_keys($previous) as $target) {
            if (isset($artifacts[$target])) {
                continue;
            }

            $path = rtrim($outputDir, '/') . '/' . $target;
            if (is_file($path) && @unlink($path)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * @param list<string> $paths
     */
    private function listPaths(SymfonyStyle $io, string $label, array $paths): void
    {
        if ($paths === []) {
            return;
        }

        sort($paths, SORT_STRING);
        $shown = array_slice($paths, 0, 20);

        $io->section($label . ' (' . count($paths) . ')');
        $io->listing($shown);

        if (count($paths) > count($shown)) {
            $io->text(sprintf('… and %d more.', count($paths) - count($shown)));
        }
    }

    /**
     * @param list<Diagnostic> $diagnostics
     */
    private function renderDiagnostics(SymfonyStyle $io, array $diagnostics): void
    {
        if ($diagnostics === []) {
            return;
        }

        foreach ($diagnostics as $diagnostic) {
            $line = sprintf('[%s] %s: %s', $diagnostic->code, $diagnostic->where, $diagnostic->message);

            if ($diagnostic->severity === Diagnostic::SEVERITY_ERROR) {
                $io->error($line);
            } else {
                $io->warning($line);
            }
        }
    }

    /**
     * @param list<Diagnostic> $diagnostics
     */
    private function hasErrors(array $diagnostics): bool
    {
        foreach ($diagnostics as $diagnostic) {
            if ($diagnostic->severity === Diagnostic::SEVERITY_ERROR) {
                return true;
            }
        }

        return false;
    }
}
