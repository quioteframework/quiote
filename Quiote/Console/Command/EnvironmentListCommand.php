<?php

declare(strict_types=1);

namespace Quiote\Console\Command;

use Quiote\Config\Config;
use Quiote\Context;
use Quiote\Environment\EnvironmentSourceRegistry;
use Quiote\Support\Compiler\Diagnostic;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * `env:list` -- enumerates every environment name any registered
 * {@see \Quiote\Environment\EnvironmentSourceInterface} knows about (e.g.
 * `quioteframework/replay-azure`'s object-store-backed cassette environments),
 * so a tool that needs to offer "which environment" as a choice -- a VS Code
 * pane's dropdown, a script picking `--env` for `cassette:list` -- doesn't
 * have to know about any specific package's own partitioning scheme.
 *
 * A package contributes a source via {@see \Quiote\Plugin\PluginRegistrar::environmentSource()}.
 * With none registered (or none applicable to the current config -- a source
 * factory returning null is not an error), this reports "No environment
 * sources registered." rather than an empty table; a source that throws
 * while listing is reported as a warning diagnostic and skipped, the same
 * per-item-soft-failure convention `cassette:list` uses for an unreadable
 * cassette, rather than aborting the whole command over one failing source.
 */
#[AsCommand(name: 'env:list', description: 'List environment names known to registered environment sources')]
final class EnvironmentListCommand extends AbstractAppCommand
{
    protected function configure(): void
    {
        $this->configureAppOptions();
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON instead of a table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->bootstrapApp($input);
        $io = new SymfonyStyle($input, $output);

        $contextName = Config::getString('core.default_context', 'web');
        try {
            $container = Context::getInstance($contextName)->getContainer();
        } catch (Throwable $e) {
            $io->error(sprintf('Could not build context "%s" to resolve environment sources: %s', $contextName, $e->getMessage()));

            return self::FAILURE;
        }

        $sources = EnvironmentSourceRegistry::resolve($container);
        if ($sources === []) {
            $io->warning('No environment sources registered.');

            return self::SUCCESS;
        }

        /** @var array<string, list<string>> $bySource */
        $bySource = [];
        /** @var list<string> $collected */
        $collected = [];
        /** @var list<Diagnostic> $diagnostics */
        $diagnostics = [];
        foreach ($sources as $id => $source) {
            try {
                $names = $source->listEnvironments();
            } catch (Throwable $e) {
                $diagnostics[] = new Diagnostic(Diagnostic::SEVERITY_WARNING, 'ENVIRONMENT_SOURCE_UNAVAILABLE', $e->getMessage(), $id);
                continue;
            }
            $bySource[$source->label()] = $names;
            $collected = [...$collected, ...$names];
        }

        $names = array_values(array_unique($collected));
        sort($names);

        if ($input->getOption('json')) {
            $output->writeln(json_encode([
                'environments' => $names,
                'by_source' => $bySource,
                'diagnostics' => array_map(static fn(Diagnostic $d): array => $d->toArray(), $diagnostics),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{"environments":[],"by_source":{},"diagnostics":[]}');

            return $this->exitCodeFor($diagnostics);
        }

        foreach ($diagnostics as $diagnostic) {
            $io->warning($diagnostic->message);
        }
        if ($names === []) {
            $io->warning('No environments found.');

            return $this->exitCodeFor($diagnostics);
        }

        $io->table(
            ['Environment', 'Sources'],
            array_map(
                static fn(string $name): array => [$name, implode(', ', self::sourcesFor($name, $bySource))],
                $names,
            ),
        );

        return $this->exitCodeFor($diagnostics);
    }

    /**
     * @param array<string, list<string>> $bySource
     * @return list<string>
     */
    private static function sourcesFor(string $name, array $bySource): array
    {
        $labels = [];
        foreach ($bySource as $label => $names) {
            if (in_array($name, $names, true)) {
                $labels[] = $label;
            }
        }

        return $labels;
    }
}
