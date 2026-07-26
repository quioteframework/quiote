<?php
declare(strict_types=1);

namespace Quiote\Console\Command;

use Quiote\Config\APCuConfigCache;
use Quiote\Config\Config;
use Quiote\Config\ConfigCache;
use Quiote\Context;
use Quiote\Exception\UnreadableException;
use Quiote\Routing\Compiler\CompiledMatcherDumper;
use Quiote\Support\Compiler\ArtifactDriftChecker;
use Quiote\Support\Compiler\FilesystemArtifactWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Compiles the app's configuration ahead of time so a freshly started worker
 * starts warm instead of paying the first-request cost of parsing/validating/
 * XSL-transforming every config file.
 *
 * Symfony solves this with `cache:warmup`; Quiote already has the machinery
 * (ConfigCache / APCuConfigCache compile config -> PHP -> file/APCu, then
 * include), it just had no CLI to drive it offline. This command is that CLI.
 *
 * Backend is auto-detected the same way the runtime picks it: if
 * QUIOTE_USE_APCU_CONFIG_CACHE is defined and true (set by Kernel::bootstrap
 * when APCu is enabled), the APCu warmup path runs; otherwise the on-disk
 * cache under {app_dir}/cache/config is populated. APCu is per-process shared
 * memory, so warming it only makes sense inside the worker runtime, not from a
 * detached CLI where apc.enable_cli is typically 0 -- for that case run the
 * file backend and let the worker's QUIOTE_APCU_PREWARM hydrate APCu at boot.
 * @since      1.0.0
 */
#[AsCommand(name: 'cache:warmup', description: 'Compile and cache configuration ahead of time so workers start warm')]
final class CacheWarmupCommand extends AbstractAppCommand
{
    protected function configure(): void
    {
        $this->configureAppOptions();
        $this->addOption('context', null, InputOption::VALUE_REQUIRED, 'Context to warm (defaults to core.default_context, else "web")');
        $this->addOption('check', null, InputOption::VALUE_NONE, 'Verify the compiled routing matcher is up to date without writing; exit non-zero on drift (CI guard)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->bootstrapApp($input);
        $io = new SymfonyStyle($input, $output);

        $contextOption = $input->getOption('context');
        $context = is_string($contextOption) ? $contextOption : Config::getString('core.default_context', 'web');

        if ($input->getOption('check')) {
            return $this->checkRoutingDrift($context, $io);
        }

        $useApcu = defined('QUIOTE_USE_APCU_CONFIG_CACHE') && QUIOTE_USE_APCU_CONFIG_CACHE;

        $io->title(sprintf('Warming Quiote cache (context: %s, backend: %s)', $context, $useApcu ? 'APCu' : 'file'));

        $configs = APCuConfigCache::getDefaultConfigs();

        if ($useApcu) {
            $stats = APCuConfigCache::warmup($configs, $context);
            $configsWarmed = is_int($stats['configs_warmed'] ?? null) ? $stats['configs_warmed'] : 0;
            $io->writeln(sprintf('Configs warmed: <info>%d</info>', $configsWarmed));
            $io->writeln(sprintf('Routing (APCu) warmed: <info>%s</info>', ($stats['routing_warmed'] ?? false) ? 'yes' : 'no'));
            $errors = is_array($stats['errors'] ?? null) ? $stats['errors'] : [];
            foreach ($errors as $err) {
                $io->warning(is_string($err) ? $err : 'Unknown warmup error.');
            }
            $durationSeconds = is_numeric($stats['duration'] ?? null) ? (float) $stats['duration'] : 0.0;
            $io->writeln(sprintf('APCu config cache warmed in <info>%.1f ms</info>.', $durationSeconds * 1000));
        } else {
            $configDir = Config::getString('core.config_dir');
            $warmed = 0;
            $skipped = [];
            $rows = [];
            foreach ($configs as $config) {
                $path = $configDir . '/' . $config;
                try {
                    ConfigCache::checkConfig($path, $context);
                    $warmed++;
                    $rows[] = [$config, '<info>compiled</info>'];
                } catch (UnreadableException) {
                    // Several default configs are optional (databases.xml,
                    // translation.xml, ...); a missing one is not an error.
                    $skipped[] = $config;
                    $rows[] = [$config, '<comment>skipped (absent)</comment>'];
                } catch (\Throwable $e) {
                    $rows[] = [$config, '<error>error: ' . $e->getMessage() . '</error>'];
                }
            }

            $io->table(['Config', 'Status'], $rows);
            $io->writeln(sprintf('Warmed <info>%d</info> config file(s) into %s/config%s.', $warmed, Config::getString('core.cache_dir'), $skipped ? sprintf(' (%d optional file(s) absent)', count($skipped)) : ''));
        }

        $this->dumpRoutingIr($context, $io);
        $this->dumpCompiledMatcher($context, $io);

        $io->success('Cache warmed.');
        return self::SUCCESS;
    }

    /**
     * Dump the routing IR (AttributeRouteScanner's scan result) so
     * AttributeRouting::build() can skip the live scan entirely under
     * core.routing.trust_compiled_ir. Only applies to attribute-based routing
     * (a generated-routes Routing subclass has no scan to skip); non-fatal
     * either way.
     */
    private function dumpRoutingIr(string $context, SymfonyStyle $io): void
    {
        try {
            $routing = Context::getInstance($context)->getRouting();
        } catch (\Throwable $e) {
            $io->warning('Skipped routing IR: ' . $e->getMessage());
            return;
        }
        if (!$routing instanceof \Quiote\Routing\AttributeRouting) {
            $io->writeln('Routing IR: <comment>skipped (not attribute-based routing)</comment>.');
            return;
        }
        try {
            // Re-scan directly with the default inputs (moduleDirs=null):
            // the same default AttributeRouting::build() falls back to, and
            // the only case RoutingIrDumper's fixed target key covers.
            $scanner = new \Quiote\Routing\Compiler\AttributeRouteScanner();
            $plan = $scanner->scan(null);
            $artifact = \Quiote\Routing\Compiler\RoutingIrDumper::emit($plan);
            (new FilesystemArtifactWriter())->write($artifact, $artifact->targetHint);
        } catch (\Throwable $e) {
            $io->warning('Skipped routing IR: ' . $e->getMessage());
            return;
        }
        $io->writeln(sprintf('Routing IR: <info>%d route(s)</info> -> %s', count($plan->routes), $artifact->targetHint));
    }

    /**
     * Dump the Symfony CompiledUrlMatcher for the context's routes so a cold
     * worker uses the opcache-native matcher instead of building the dynamic
     * one. Non-fatal: a routing service that can't be resolved (or has no
     * routes) just skips this step.
     */
    private function dumpCompiledMatcher(string $context, SymfonyStyle $io): void
    {
        try {
            $routes = Context::getInstance($context)->getRouting()->getRouteCollection();
        } catch (\Throwable $e) {
            $io->warning('Skipped compiled routing matcher: ' . $e->getMessage());
            return;
        }
        if (count($routes) === 0) {
            $io->writeln('Compiled routing matcher: <comment>skipped (no routes)</comment>.');
            return;
        }
        try {
            $artifact = CompiledMatcherDumper::emit($routes);
            (new FilesystemArtifactWriter())->write($artifact, $artifact->targetHint);
        } catch (\Throwable $e) {
            // Some legacy route patterns the dynamic UrlMatcher tolerates (it
            // compiles routes lazily, per match) are rejected by Symfony's
            // eager dumper. That's not fatal: without a dump, the runtime keeps
            // using the dynamic matcher. Skip and tell the user why.
            $io->writeln('Compiled routing matcher: <comment>skipped (routes not compilable: ' . $e->getMessage() . ')</comment>');
            return;
        }
        $io->writeln(sprintf('Compiled routing matcher: <info>%d route(s)</info> -> %s', count($routes), $artifact->targetHint));
    }

    /**
     * --check: emit the compiled matcher in memory and compare it to what is on
     * disk without writing. Exit non-zero if it is missing or stale so CI can
     * catch a route change that wasn't followed by `cache:warmup`.
     */
    private function checkRoutingDrift(string $context, SymfonyStyle $io): int
    {
        try {
            $routes = Context::getInstance($context)->getRouting()->getRouteCollection();
        } catch (\Throwable $e) {
            $io->error('Could not resolve routing service for context "' . $context . '": ' . $e->getMessage());
            return self::FAILURE;
        }
        try {
            $artifact = CompiledMatcherDumper::emit($routes);
        } catch (\Throwable $e) {
            // Routes not compilable by Symfony's dumper -> there is no compiled
            // matcher to keep in sync; the runtime uses the dynamic matcher.
            // Not a drift failure.
            $io->warning('Compiled routing matcher not applicable (routes not compilable): ' . $e->getMessage());
            return self::SUCCESS;
        }
        $result = (new ArtifactDriftChecker())->check($artifact, $artifact->targetHint);
        if ($result->matches) {
            $io->success('Compiled routing matcher is up to date.');
            return self::SUCCESS;
        }
        $io->error(sprintf(
            "Compiled routing matcher is %s.\nRun `quiote cache:warmup --context %s` and commit/deploy the result.\nExpected: %s",
            is_file($artifact->targetHint) ? 'stale' : 'missing',
            $context,
            $artifact->targetHint
        ));
        return self::FAILURE;
    }
}
