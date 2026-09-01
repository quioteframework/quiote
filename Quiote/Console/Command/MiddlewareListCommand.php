<?php
declare(strict_types=1);

namespace Quiote\Console\Command;

use Quiote\Middleware\Compiler\MiddlewareDefinition;
use Quiote\Middleware\CoreMiddlewareRegistry;
use Quiote\Middleware\MiddlewareCatalog;
use Quiote\Middleware\MiddlewarePipeline;
use Quiote\Middleware\ValidationMiddleware;
use Quiote\Support\Compiler\Diagnostic;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lists the middleware pipeline in the exact order it would run for a real
 * request, sourced from {@see MiddlewarePipeline::resolveOrder()} -- the same
 * scan/merge/resolve computation `MiddlewarePipeline::doBuild()` uses before
 * it instantiates anything, so this command and the real pipeline can never
 * disagree. Building it this way (rather than actually constructing the
 * pipeline) means listing it has no side effects: no session is opened, no
 * database is touched, nothing a middleware's constructor might otherwise do
 * happens just to print a table.
 *
 * Middleware is process-global, not per-context (see
 * {@see MiddlewareCatalog}'s statics), so unlike `routes:list` there is no
 * `--context` option here.
 *
 * Externally {@see MiddlewareCatalog::register()}-ed middleware is spliced
 * into the resolved order at its `after`/`before` hint (default: right after
 * {@see ValidationMiddleware}), mirroring
 * {@see MiddlewarePipeline}'s own `insertRegistered()`/`findInsertPosition()`
 * placement rule against FQCNs alone -- introspection needs no instances to
 * compute where they land.
 * @since      1.0.0
 */
#[AsCommand(name: 'middleware:list', description: "List the app's resolved middleware pipeline, in run order")]
final class MiddlewareListCommand extends AbstractAppCommand
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

        if (MiddlewareCatalog::hasCoreStackOverride()) {
            return $this->reportOverride($input, $output, $io);
        }

        $resolved = MiddlewarePipeline::resolveOrder();

        $enabled = [];
        $disabled = [];
        /** @var array<string, MiddlewareDefinition> $byFqcn */
        $byFqcn = [];
        foreach ($resolved['ordered'] as $entry) {
            $definition = $entry['definition'];
            $byFqcn[$definition->fqcn] = $definition;
            if ($entry['enabled']) {
                $enabled[] = $definition->fqcn;
            } else {
                $disabled[] = $definition->fqcn;
            }
        }

        $rows = $this->spliceRegistered($enabled, $byFqcn);

        if ($input->getOption('json')) {
            $payload = [
                'middleware' => $rows,
                'disabled' => $disabled,
                'diagnostics' => array_map(static fn(Diagnostic $d) => $d->toArray(), $resolved['diagnostics']),
            ];
            $output->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{"middleware":[],"disabled":[],"diagnostics":[]}');
            return $this->exitCodeFor($resolved['diagnostics']);
        }

        $this->renderDiagnostics($io, $resolved['diagnostics']);

        if (!$rows) {
            $io->warning('No middleware resolved.');
            return $this->exitCodeFor($resolved['diagnostics']);
        }

        $io->table(
            ['#', 'Class', 'Phase', 'Priority', 'Before', 'After', 'Source'],
            array_map(static fn(array $row, int $i) => [
                $i + 1,
                $row['fqcn'],
                $row['phase'] ?? '-',
                $row['priority'] ?? '-',
                $row['before'] ?? '',
                $row['after'] ?? '',
                $row['source'],
            ], $rows, array_keys($rows))
        );

        if ($disabled) {
            $io->note(sprintf('Disabled, excluded from the pipeline: %s', implode(', ', $disabled)));
        }

        return $this->exitCodeFor($resolved['diagnostics']);
    }

    /**
     * Reports that the core stack has been replaced via
     * {@see MiddlewareCatalog::replaceCoreStack()}: its classes only exist by
     * invoking the app-supplied factory against a real request, and
     * registered middleware is not spliced into it (see that method's own
     * docblock), so there is nothing this command can list statically.
     */
    private function reportOverride(InputInterface $input, OutputInterface $output, SymfonyStyle $io): int
    {
        $message = 'The core middleware stack has been replaced via MiddlewareCatalog::replaceCoreStack(); '
            . 'its classes are only known by invoking the app-supplied factory, and registered() middleware '
            . 'is not spliced into it. Nothing to list statically.';

        if ($input->getOption('json')) {
            $output->writeln(json_encode(
                ['overridden' => true, 'middleware' => [], 'disabled' => [], 'diagnostics' => []],
                JSON_PRETTY_PRINT
            ) ?: '{"overridden":true}');
            return self::SUCCESS;
        }

        $io->warning($message);
        return self::SUCCESS;
    }

    /**
     * Splices externally {@see MiddlewareCatalog::register()}-ed middleware into the resolved
     * FQCN order, at each entry's `after`/`before` hint -- the same placement
     * {@see MiddlewarePipeline}'s own `insertRegistered()` applies to real instances, computed
     * here against FQCNs alone so listing needs no instances.
     *
     * @param list<string> $order Resolved, enabled FQCNs, already in final scan order.
     * @param array<string, MiddlewareDefinition> $byFqcn
     * @return list<array{fqcn: string, phase: ?string, priority: ?int, before: ?string, after: ?string, source: string}>
     */
    private function spliceRegistered(array $order, array $byFqcn): array
    {
        $rows = array_map(
            static fn(string $fqcn): array => [
                'fqcn' => $fqcn,
                'phase' => $byFqcn[$fqcn]->phase,
                'priority' => $byFqcn[$fqcn]->priority,
                'before' => $byFqcn[$fqcn]->before,
                'after' => $byFqcn[$fqcn]->after,
                'source' => self::classifySource($byFqcn[$fqcn]),
            ],
            $order
        );

        $registeredEntries = MiddlewareCatalog::getRegistered();
        if (!$registeredEntries) {
            return $rows;
        }

        uasort($registeredEntries, static fn(array $a, array $b): int => $b['priority'] <=> $a['priority']);

        foreach ($registeredEntries as $entry) {
            if (!MiddlewareCatalog::isEnabled($entry['fqcn'])) {
                continue;
            }
            $pos = self::findInsertPosition($order, $entry['after'], $entry['before']);
            $row = [
                'fqcn' => $entry['fqcn'],
                'phase' => null,
                'priority' => $entry['priority'],
                'before' => $entry['before'],
                'after' => $entry['after'],
                'source' => 'Registered',
            ];
            array_splice($order, $pos, 0, [$entry['fqcn']]);
            array_splice($rows, $pos, 0, [$row]);
        }

        return $rows;
    }

    /**
     * Mirrors {@see MiddlewarePipeline}'s own `findInsertPosition()`: `after` wins over `before`,
     * and with neither resolvable the default is right after {@see ValidationMiddleware}, or the
     * end of the stack if that isn't present either.
     * @param list<string> $order
     */
    private static function findInsertPosition(array $order, ?string $after, ?string $before): int
    {
        if ($after !== null) {
            $idx = array_search($after, $order, true);
            if ($idx !== false) {
                return $idx + 1;
            }
        }

        if ($before !== null) {
            $idx = array_search($before, $order, true);
            if ($idx !== false) {
                return $idx;
            }
        }

        $idx = array_search(ValidationMiddleware::class, $order, true);
        if ($idx !== false) {
            return $idx + 1;
        }

        return count($order);
    }

    /**
     * Classifies where a definition came from for the Source column:
     * "Config" when `middleware.xml`/etc. explicitly named this class (the merge in
     * {@see MiddlewarePipeline}'s `mergeConfigDefinitions()` always overwrites `sourceRef` with
     * the config entry's own when it does), "Core"/"Plugin" for the framework's own declared
     * classes, and "Attribute" for everything else -- app/plugin middleware opted in via
     * {@see MiddlewareCatalog::registerAttributed()} and ordered by its own `#[Middleware]`.
     */
    private static function classifySource(MiddlewareDefinition $definition): string
    {
        if ($definition->sourceRef !== $definition->fqcn) {
            return 'Config';
        }
        if (in_array($definition->fqcn, CoreMiddlewareRegistry::CORE, true)) {
            return 'Core';
        }
        if (in_array($definition->fqcn, CoreMiddlewareRegistry::pluginProvidedClasses(), true)) {
            return 'Plugin';
        }
        return 'Attribute';
    }

    /**
     * @param Diagnostic[] $diagnostics
     */
    private function renderDiagnostics(SymfonyStyle $io, array $diagnostics): void
    {
        foreach ($diagnostics as $diagnostic) {
            $message = sprintf('[%s] %s (%s)', $diagnostic->code, $diagnostic->message, $diagnostic->where);
            if ($diagnostic->severity === Diagnostic::SEVERITY_ERROR) {
                $io->error($message);
            } else {
                $io->warning($message);
            }
        }
    }

    /**
     * @param Diagnostic[] $diagnostics
     */
    private function exitCodeFor(array $diagnostics): int
    {
        foreach ($diagnostics as $diagnostic) {
            if ($diagnostic->severity === Diagnostic::SEVERITY_ERROR) {
                return self::FAILURE;
            }
        }
        return self::SUCCESS;
    }
}
