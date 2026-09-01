<?php
declare(strict_types=1);

namespace Quiote\Console\Command;

use Quiote\Config\Config;
use Quiote\Plugin\PluginConfigRegistry;
use Quiote\Plugin\PluginManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lists every plugin registered for the app -- i.e. exactly what
 * {@see PluginManager::registeredPlugins()} holds once `Quiote::bootstrap()`
 * has read the `plugins` config key (plus any programmatic
 * `PluginManager::add()` calls a bootstrap file made) and invoked
 * `register()` on each.
 *
 * Listed in declared/registration order, not sorted -- that order is itself
 * meaningful (see {@see PluginManager::bootFromConfig()}: `register()` runs
 * in this order, and a plugin that depends on another's contribution having
 * already run cares which came first).
 *
 * Does not include `quioteframework/csrf`'s plugin: {@see \Quiote\Quiote::bootstrap()}
 * registers it directly (a deliberate opt-out-not-opt-in security default), never
 * through {@see PluginManager}, so it never appears here even though `middleware:list`
 * shows its middleware as active.
 * @since      1.0.0
 */
#[AsCommand(name: 'plugins:list', description: 'List plugins registered with the app')]
final class PluginsListCommand extends AbstractAppCommand
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

        $plugins = [];
        foreach (PluginManager::registeredPlugins() as $class => $plugin) {
            $sourceRef = PluginConfigRegistry::sourceRefFor($class);
            $plugins[] = [
                'name' => PluginManager::resolveName($plugin),
                'class' => $class,
                'source' => self::classifySource($sourceRef),
                'sourceRef' => $sourceRef,
            ];
        }

        if ($input->getOption('json')) {
            $output->writeln(json_encode(['plugins' => $plugins], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{"plugins":[]}');
            return self::SUCCESS;
        }

        if (!$plugins) {
            $io->warning('No plugins registered.');
            return self::SUCCESS;
        }

        $io->table(
            ['Name', 'Class', 'Source'],
            array_map(static fn(array $plugin) => [$plugin['name'], $plugin['class'], $plugin['source']], $plugins)
        );

        return self::SUCCESS;
    }

    /**
     * Classifies where a plugin was declared from, for the Source column: "Global" for the app's
     * own `plugins.*`, "Module <name>" for a specific module's, each with the declaring file's
     * format in parens, or "Code" when {@see PluginConfigRegistry} has no file for this class at
     * all -- meaning it was activated by handing {@see PluginManager::add()} an already-built
     * instance directly, not declared in any config file. A bare `#[Plugin]` attribute never
     * activates a plugin by itself (see that attribute's own docblock), so there is no separate
     * "attribute" source: every plugin is one of these two.
     */
    private static function classifySource(?string $sourceRef): string
    {
        if ($sourceRef === null) {
            return 'Code';
        }

        $format = strtolower((string) pathinfo($sourceRef, PATHINFO_EXTENSION)) ?: '?';
        $moduleDir = rtrim(Config::getString('core.module_dir', ''), '/');
        if ($moduleDir !== '' && str_starts_with($sourceRef, $moduleDir . '/')) {
            $relative = substr($sourceRef, strlen($moduleDir) + 1);
            $moduleName = strstr($relative, '/', true);
            return sprintf('Module %s (%s)', $moduleName !== false ? $moduleName : $relative, $format);
        }

        return sprintf('Global (%s)', $format);
    }
}
